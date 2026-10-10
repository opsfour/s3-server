#!/usr/bin/env python3
"""Test-only relay launcher; keeps the original signed backing hostname."""

import os
import socket
import sys


def main():
    role = sys.argv[1] if len(sys.argv) == 2 else "relay"
    own_ip = socket.gethostbyname(socket.gethostname())
    if role == "origin":
        os.execv(sys.executable, [sys.executable, "/app/tests/Support/provider_path_tls_proxy.py",
                                "--synthetic-origin", "--listen-ip", own_ip,
                                "--listen-port", "443", "--cert", "/gate/relay.pem",
                                "--key", "/gate/relay.key"])
    if role != "relay":
        raise SystemExit("role must be origin or relay")
    host = os.environ["S3_GATE_ORIGIN_HOST"]
    synthetic = os.environ.get("S3_GATE_MODE") == "synthetic"
    origin_ip = socket.gethostbyname("origin") if synthetic else os.environ["S3_GATE_ORIGIN_IP"]
    command = [sys.executable, "/app/tests/Support/provider_path_tls_proxy.py",
               "--listen-ip", own_ip,
               "--listen-port", "443",
               "--origin-host", host,
               "--origin-ip", origin_ip,
               "--origin-port", "443",
               "--cert", "/gate/relay.pem",
               "--key", "/gate/relay.key",
               "--evidence", "/gate/relay-events.jsonl",
               "--hold-seconds", "25"]
    if synthetic:
        command += ["--synthetic-origin-ca", "/gate/relay.pem"]
    os.execv(sys.executable, command)


if __name__ == "__main__":
    main()
