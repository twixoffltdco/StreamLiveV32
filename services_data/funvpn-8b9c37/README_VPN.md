# VPN auto-finder and connector (VPNGate)

This repository addition provides a small Node.js helper that:

- Fetches the VPN server list from VPNGate (https://www.vpngate.net/api/iphone/)
- Decodes available OpenVPN (.ovpn) configs
- Tests connectivity to the server
- Saves the first working .ovpn into ./configs/
- If you run the script as root, it will start openvpn automatically using that .ovpn file; otherwise it will print the sudo command to run.

Requirements
- Linux/macOS
- Node.js (12+ recommended)
- openvpn installed (for actual connection)

Usage
1. Make scripts executable:
   chmod +x scripts/run_vpn.sh

2. Run the helper (it will pick the first reachable server and save the config):
   ./scripts/run_vpn.sh

3. If you ran as a normal user, the script will print a command to run as root, e.g.:
   sudo openvpn --config ./configs/<name>.ovpn

Notes
- This script downloads publicly-available free VPN configs from VPNGate. These are public servers, usually free, and availability changes frequently.
- The script attempts a TCP-connect test to the server and port listed in the .ovpn. Some servers use UDP and may appear unreachable by TCP even if they actually work; in that case try the generated .ovpn manually.
- Running openvpn requires root privileges.

Security and disclaimers
- Use these free VPN servers at your own risk. The project does not vouch for the privacy/security of any particular server.
- Do not hard-delete or overwrite other files in this repository – the script saves to ./configs/.

If you want this behavior integrated into other parts of the project (for example an automated runner or a UI), tell me where and I will adapt the code accordingly.
