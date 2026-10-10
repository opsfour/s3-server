#!/usr/bin/env python3
"""Container-only synthetic origin/relay launcher for the isolated local gate."""

import os
import socket
import sys


def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ("origin", "relay"):
        raise SystemExit("role must be origin or relay")
    own_ip = socket.gethostbyname(socket.gethostname())
    command = [sys.executable, "/app/tests/Support/provider_path_tls_proxy.py",
               "--listen-ip", own_ip, "--listen-port", "9443",
               "--cert", "/gate/relay.pem", "--key", "/gate/relay.key"]
    if sys.argv[1] == "origin":
        command.append("--synthetic-origin")
    else:
        command += ["--origin-host", "provider.invalid",
                    "--request-host", "provider.invalid:9443",
                    "--origin-ip", socket.gethostbyname("origin"),
                    "--origin-port", "9443",
                    "--synthetic-origin-ca", "/gate/relay.pem",
                    "--evidence", "/gate/relay-events.jsonl",
                    "--hold-seconds", "2.5"]
    os.execv(sys.executable, command)


if __name__ == "__main__":
    main()
