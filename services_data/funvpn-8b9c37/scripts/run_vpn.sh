#!/usr/bin/env bash
# Helper script to run the connect_vpn.js and (optionally) start openvpn if run as root.
# Usage: ./scripts/run_vpn.sh
set -e
NODE=$(command -v node || true)
if [ -z "$NODE" ]; then
  echo "Node.js is required. Please install node and try again."
  exit 1
fi
# Run the Node script which will download and save a working .ovpn
node "$(dirname "$0")/connect_vpn.js"
