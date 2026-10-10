#!/usr/bin/env python3
"""One-shot, test-only HTTPS GET fault relay. Never logs requests or secrets."""

import argparse
import datetime
import hashlib
import ipaddress
import json
import os
import re
import socket
import socketserver
import ssl
import subprocess
import tempfile
import threading
import time
from pathlib import Path
from urllib.parse import parse_qs, unquote, urlsplit


def read_headers(connection):
    data = bytearray()
    while b"\r\n\r\n" not in data:
        chunk = connection.recv(8192)
        if not chunk or len(data) + len(chunk) > 65536:
            raise ValueError("Invalid HTTP header block")
        data.extend(chunk)
    end = data.index(b"\r\n\r\n") + 4
    return bytes(data[:end]), bytes(data[end:])


def content_length(headers):
    values = [line.split(b":", 1)[1].strip() for line in headers.split(b"\r\n")
              if line.lower().startswith(b"content-length:")]
    if len(values) != 1 or not values[0].isdigit():
        raise ValueError("A single Content-Length is required")
    return int(values[0])


def utc_now():
    return datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="milliseconds")


def valid_listen_ip(value):
    address = ipaddress.ip_address(value)
    return address.version == 4 and not address.is_unspecified \
        and not address.is_multicast and (address.is_private or address.is_loopback)


class RelayState:
    def __init__(self, host, request_host, ip, port, hold, evidence, upstream_context):
        self.host = host
        self.request_host = request_host
        self.ip = ip
        self.port = port
        self.hold = hold
        self.evidence = evidence
        self.upstream_context = upstream_context
        self.lock = threading.Lock()
        self.fault_used = False

    def event(self, name, started):
        if self.evidence is None:
            return
        record = {"event": name, "utc": utc_now(),
                  "elapsed_ms": round((time.monotonic() - started) * 1000, 3)}
        with self.lock:
            with open(self.evidence, "a", encoding="utf-8") as output:
                output.write(json.dumps(record, separators=(",", ":")) + "\n")

    def take_fault(self):
        with self.lock:
            if self.fault_used:
                return False
            self.fault_used = True
            return True


class Handler(socketserver.BaseRequestHandler):
    def handle(self):
        started = time.monotonic()
        state = self.server.state
        upstream = None
        try:
            request, pending = read_headers(self.request)
            first_line = request.split(b"\r\n", 1)[0]
            host_lines = [line.split(b":", 1)[1].strip().lower()
                          for line in request.split(b"\r\n")
                          if line.lower().startswith(b"host:")]
            if not first_line.startswith(b"GET ") or len(host_lines) != 1 \
                    or host_lines[0] != state.request_host.encode("ascii").lower() or pending:
                self.request.sendall(b"HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\nConnection: close\r\n\r\n")
                state.event("invalid_request", started)
                return
            state.event("request_received", started)
            raw = socket.create_connection((state.ip, state.port), timeout=10)
            upstream = state.upstream_context.wrap_socket(raw, server_hostname=state.host)
            upstream.settimeout(35)
            upstream.sendall(request)  # Host and all SigV4-signed bytes stay unchanged.
            headers, pending = read_headers(upstream)
            length = content_length(headers)
            if length > 1024 * 1024:
                raise ValueError("GET fault object exceeds one MiB")
            state.event("origin_headers_received", started)
            self.request.sendall(headers)
            if state.take_fault():
                state.event("fault_before_body", started)
                time.sleep(state.hold)
            remaining = length
            while remaining:
                chunk = pending if pending else upstream.recv(min(65536, remaining))
                pending = b""
                if not chunk:
                    raise EOFError("Origin body ended early")
                chunk = chunk[:remaining]
                self.request.sendall(chunk)
                remaining -= len(chunk)
            state.event("body_forwarded", started)
        except (OSError, ssl.SSLError, ValueError, EOFError):
            state.event("connection_closed", started)
        finally:
            if upstream is not None:
                upstream.close()


class Relay(socketserver.ThreadingMixIn, socketserver.TCPServer):
    allow_reuse_address = True
    daemon_threads = True

    def __init__(self, bind, state, server_context):
        self.state = state
        self.server_context = server_context
        super().__init__(bind, Handler)

    def get_request(self):
        connection, address = super().get_request()
        try:
            return self.server_context.wrap_socket(connection, server_side=True), address
        except ssl.SSLError:
            connection.close()
            raise


class SyntheticOriginHandler(socketserver.BaseRequestHandler):
    def handle(self):
        try:
            request, pending = read_headers(self.request)
            first = request.split(b"\r\n", 1)[0].split(b" ")
            if len(first) != 3:
                return
            method = first[0]
            target = urlsplit(first[1].decode("ascii"))
            key = unquote(target.path)
            if key == "/remote-bucket/fault-gate/item" and method in (b"GET", b"HEAD"):
                body = b"x" * 65536
                self._reply(200, b"" if method == b"HEAD" else body,
                            b"Content-Length: 65536\r\n" if method == b"HEAD" else b"")
                return
            if method == b"GET" and key in ("/remote-bucket", "/remote-bucket/") \
                    and "list-type" in parse_qs(target.query):
                prefix = parse_qs(target.query).get("prefix", [""])[0]
                with self.server.lock:
                    keys = [item.removeprefix("/remote-bucket/") for item in self.server.objects
                            if item.removeprefix("/remote-bucket/").startswith(prefix)]
                contents = b"".join(b"<Contents><Key>" + item.encode() + b"</Key></Contents>" for item in keys)
                xml = b"<ListBucketResult xmlns=\"http://s3.amazonaws.com/doc/2006-03-01/\"><Name>remote-bucket</Name><Prefix></Prefix><KeyCount>" + str(len(keys)).encode() + b"</KeyCount><MaxKeys>1000</MaxKeys><IsTruncated>false</IsTruncated>" + contents + b"</ListBucketResult>"
                self._reply(200, xml)
                return
            if not key.startswith("/remote-bucket/s3-server/provider-fault/"):
                self._reply(404, b"<Error><Code>NoSuchKey</Code></Error>")
                return
            if method == b"PUT":
                length = content_length(request)
                if length > 1024 * 1024:
                    self._reply(413, b"")
                    return
                if b"expect: 100-continue" in request.lower():
                    self.request.sendall(b"HTTP/1.1 100 Continue\r\n\r\n")
                body = bytearray(pending)
                while len(body) < length:
                    chunk = self.request.recv(min(65536, length - len(body)))
                    if not chunk:
                        return
                    body.extend(chunk)
                if len(body) != length:
                    return
                with self.server.lock:
                    self.server.objects[key] = bytes(body)
                self._reply(200, b"", b'ETag: "' + hashlib.md5(body).hexdigest().encode() + b'"\r\n')
                return
            if method == b"DELETE":
                with self.server.lock:
                    self.server.objects.pop(key, None)
                self._reply(204, b"")
                return
            if method in (b"GET", b"HEAD"):
                with self.server.lock:
                    body = self.server.objects.get(key)
                if body is None:
                    self._reply(404, b"<Error><Code>NoSuchKey</Code></Error>")
                else:
                    self._reply(200, b"" if method == b"HEAD" else body,
                                b"Content-Length: " + str(len(body)).encode() + b"\r\n" if method == b"HEAD" else b"")
                return
            self._reply(405, b"")
        except (OSError, ssl.SSLError, ValueError):
            pass

    def _reply(self, status, body, extra=b""):
        reason = {200: b"OK", 204: b"No Content", 404: b"Not Found", 405: b"Method Not Allowed", 413: b"Payload Too Large"}[status]
        length = len(body)
        if status == 204:
            length = 0
        if b"Content-Length:" in extra:
            header = extra
        else:
            header = b"Content-Length: " + str(length).encode() + b"\r\n" + extra
        self.request.sendall(b"HTTP/1.1 " + str(status).encode() + b" " + reason + b"\r\n" + header + b"Connection: close\r\n\r\n" + body)


class SyntheticOrigin(Relay):
    def __init__(self, bind, server_context):
        self.server_context = server_context
        self.objects = {}
        self.lock = threading.Lock()
        socketserver.TCPServer.__init__(self, bind, SyntheticOriginHandler)


def self_test():
    if not valid_listen_ip("127.0.0.1") or not valid_listen_ip("172.30.0.2") \
            or valid_listen_ip("0.0.0.0") or valid_listen_ip("8.8.8.8"):
        raise AssertionError("Relay bind-address policy failed")
    with tempfile.TemporaryDirectory(prefix="s3-provider-path-selftest-") as directory:
        cert = Path(directory) / "cert.pem"
        key = Path(directory) / "key.pem"
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes",
                        "-days", "1", "-subj", "/CN=provider.invalid",
                        "-addext", "subjectAltName=DNS:provider.invalid",
                        "-keyout", str(key), "-out", str(cert)],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        server_context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        server_context.load_cert_chain(cert, key)
        client_context = ssl.create_default_context(cafile=str(cert))
        observed = []

        def origin():
            for _ in range(2):
                connection, _ = origin_listener.accept()
                with server_context.wrap_socket(connection, server_side=True) as peer:
                    request, pending = read_headers(peer)
                    observed.append(request)
                    if pending:
                        raise AssertionError("Unexpected GET request body")
                    peer.sendall(b"HTTP/1.1 200 OK\r\nContent-Length: 4\r\nConnection: close\r\n\r\npass")

        origin_listener = socket.socket()
        origin_listener.bind(("127.0.0.1", 0))
        origin_listener.listen(2)
        upstream = threading.Thread(target=origin, daemon=True)
        upstream.start()
        state = RelayState("provider.invalid", "provider.invalid", "127.0.0.1", origin_listener.getsockname()[1],
                           0.3, None, client_context)
        with Relay(("127.0.0.1", 0), state, server_context) as relay:
            worker = threading.Thread(target=relay.serve_forever, daemon=True)
            worker.start()
            times = []
            request = b"GET /fixed HTTP/1.1\r\nHost: provider.invalid\r\nAuthorization: fake-signature\r\nConnection: close\r\n\r\n"
            for _ in range(2):
                started = time.monotonic()
                raw = socket.create_connection(relay.server_address, timeout=2)
                with client_context.wrap_socket(raw, server_hostname="provider.invalid") as client:
                    client.sendall(request)
                    response, pending = read_headers(client)
                    while len(pending) < 4:
                        pending += client.recv(4 - len(pending))
                    if not response.startswith(b"HTTP/1.1 200") or pending != b"pass":
                        raise AssertionError("Proxy changed the response")
                times.append(time.monotonic() - started)
            relay.shutdown()
            worker.join(timeout=2)
        upstream.join(timeout=2)
        origin_listener.close()
        if observed != [request, request] or times[0] < 0.25 or times[1] >= 0.25:
            raise AssertionError("One-shot fault or byte-preserving relay failed")
    print("provider-path TLS relay self-test: pass")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--self-test", action="store_true")
    parser.add_argument("--synthetic-origin", action="store_true")
    parser.add_argument("--origin-host")
    parser.add_argument("--request-host")
    parser.add_argument("--origin-ip")
    parser.add_argument("--origin-port", type=int, default=443)
    parser.add_argument("--cert")
    parser.add_argument("--key")
    parser.add_argument("--synthetic-origin-ca")
    parser.add_argument("--hold-seconds", type=float, default=25.0)
    parser.add_argument("--evidence")
    parser.add_argument("--listen-ip", default="127.0.0.1")
    parser.add_argument("--listen-port", type=int, default=443)
    args = parser.parse_args()
    if args.self_test:
        self_test()
        return
    if args.synthetic_origin:
        if not args.cert or not args.key or not (0 <= args.listen_port <= 65535) \
                or not valid_listen_ip(args.listen_ip):
            parser.error("synthetic origin requires a private IPv4 bind, certificate, key, and port")
        server_context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        server_context.load_cert_chain(args.cert, args.key)
        with SyntheticOrigin((args.listen_ip, args.listen_port), server_context) as origin:
            print(f"synthetic TLS origin ready port={origin.server_address[1]}", flush=True)
            origin.serve_forever()
        return
    if not all([args.origin_host, args.origin_ip, args.cert, args.key, args.evidence]) \
            or not (0 < args.hold_seconds <= 30) or not (0 <= args.listen_port <= 65535):
        parser.error("origin host/IP, certificate, key, evidence, and bounded delay are required")
    ipaddress.ip_address(args.origin_ip)
    if not valid_listen_ip(args.listen_ip):
        parser.error("relay must bind a loopback or private-network address")
    if re.fullmatch(r"[A-Za-z0-9.-]+", args.origin_host) is None:
        parser.error("origin host must be a DNS hostname")
    if (os.getenv("SSL_CERT_FILE") or os.getenv("SSL_CERT_DIR")) and not args.synthetic_origin_ca:
        parser.error("relay upstream must use the system CA, without SSL_CERT_FILE or SSL_CERT_DIR")
    if args.synthetic_origin_ca and not (
        (args.origin_host == "localhost" and args.origin_ip == "127.0.0.1")
        or (args.origin_host.endswith(".invalid") and ipaddress.ip_address(args.origin_ip).is_private)
    ):
        parser.error("synthetic origin CA requires loopback localhost or a private .invalid test origin")
    os.umask(0o077)
    evidence = Path(args.evidence)
    if evidence.exists() and evidence.stat().st_mode & 0o077:
        parser.error("evidence file must not be readable by group or others")
    server_context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    server_context.load_cert_chain(args.cert, args.key)
    state = RelayState(args.origin_host, args.request_host or args.origin_host,
                       args.origin_ip, args.origin_port, args.hold_seconds,
                       args.evidence, ssl.create_default_context(cafile=args.synthetic_origin_ca))
    with Relay((args.listen_ip, args.listen_port), state, server_context) as relay:
        if args.listen_port == 0 and args.request_host == "localhost:0" and args.listen_ip == "127.0.0.1":
            state.request_host = f"localhost:{relay.server_address[1]}"
        print(f"provider-path TLS relay ready on loopback port={relay.server_address[1]}", flush=True)
        relay.serve_forever()


if __name__ == "__main__":
    main()
