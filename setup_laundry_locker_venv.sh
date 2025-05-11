#!/bin/bash
# Setup script for Laundry Locker System with Virtual Environment Support
# Run with sudo: sudo bash setup_laundry_locker_venv.sh

# Exit on error
set -e

INSTALL_DIR="/home/pi/laundry_locker_install"
VENV_DIR="$INSTALL_DIR/venv"
SYSTEMD_SERVICE="/etc/systemd/system/laundry_locker_api.service"
WEB_DIR="/var/www/html/laundry_locker"

print_header() {
    echo "====================================================="
    echo "$1"
    echo "====================================================="
    echo
}

print_header "Laundry Locker System Setup with Virtual Environment"

# Check if running as root
if [ "$EUID" -ne 0 ]; then
  echo "Please run as root (use sudo)"
  exit 1
fi

# Create main command function
create_command_script() {
    cat > /usr/local/bin/laundrylocker << EOF
#!/bin/bash
# Laundry Locker Control Script

case "\$1" in
    start)
        systemctl start laundry_locker_api.service
        echo "Laundry Locker API service started"
        ;;
    stop)
        systemctl stop laundry_locker_api.service
        echo "Laundry Locker API service stopped"
        ;;
    restart)
        systemctl restart laundry_locker_api.service
        echo "Laundry Locker API service restarted"
        ;;
    status)
        systemctl status laundry_locker_api.service
        ;;
    enable)
        systemctl enable laundry_locker_api.service
        echo "Laundry Locker API will start on boot"
        ;;
    disable)
        systemctl disable laundry_locker_api.service
        echo "Laundry Locker API will NOT start on boot"
        ;;
    enable-kiosk)
        if [ -f "/home/pi/.config/autostart/kiosk.desktop.disabled" ]; then
            mv /home/pi/.config/autostart/kiosk.desktop.disabled /home/pi/.config/autostart/kiosk.desktop
            echo "Kiosk mode enabled - will start on next boot"
        elif [ -f "/home/pi/.config/autostart/kiosk.desktop" ]; then
            echo "Kiosk mode is already enabled"
        else
            echo "Kiosk autostart file not found. Run setup first."
        fi
        ;;
    disable-kiosk)
        if [ -f "/home/pi/.config/autostart/kiosk.desktop" ]; then
            mv /home/pi/.config/autostart/kiosk.desktop /home/pi/.config/autostart/kiosk.desktop.disabled
            echo "Kiosk mode disabled - will not start on next boot"
        else
            echo "Kiosk mode is already disabled or not set up"
        fi
        ;;
    logs)
        journalctl -u laundry_locker_api.service -f
        ;;
    *)
        echo "Laundry Locker Control Script"
        echo "Usage: laundrylocker [command]"
        echo
        echo "Commands:"
        echo "  start         - Start the API service"
        echo "  stop          - Stop the API service"
        echo "  restart       - Restart the API service"
        echo "  status        - Check the status of the API service"
        echo "  enable        - Enable API service to start on boot"
        echo "  disable       - Disable API service from starting on boot"
        echo "  enable-kiosk  - Enable kiosk mode to start on boot"
        echo "  disable-kiosk - Disable kiosk mode from starting on boot"
        echo "  logs          - View API service logs"
        ;;
esac
EOF

    chmod +x /usr/local/bin/laundrylocker
    echo "Created control script at /usr/local/bin/laundrylocker"
}

# Ask for confirmation
echo "This script will set up your Raspberry Pi with a virtual environment for"
echo "the Laundry Locker System. It will:"
echo "  - Install required packages (Apache, PHP, etc.)"
echo "  - Create a Python virtual environment"
echo "  - Configure the system to run the API server from the virtual environment"
echo "  - Set up kiosk mode (optional)"
echo "  - Create control commands to enable/disable startup on boot"
echo
echo "Press Enter to continue or Ctrl+C to cancel..."
read

# Update system
print_header "Updating system packages"
apt update
apt upgrade -y

# Install required packages
print_header "Installing required packages"
apt install -y apache2 php php-curl unclutter chromium-browser python3-venv python3-pip

# Enable PHP
echo "Enabling PHP in Apache..."
a2enmod php || echo "PHP module already enabled"

# Create project directories
print_header "Creating project directories"
mkdir -p "$INSTALL_DIR"
mkdir -p "$WEB_DIR"

# Create and activate virtual environment
print_header "Setting up Python virtual environment"
python3 -m venv "$VENV_DIR"
# We need to use source to activate in the same shell
source "$VENV_DIR/bin/activate"

# Install Python requirements in the virtual environment
print_header "Installing Python requirements in virtual environment"
pip install Flask Flask-CORS RPi.GPIO mfrc522 requests

# Copy project files
print_header "Copying project files"
# Copy PHP files to web directory
cp "$INSTALL_DIR/index.php" "$WEB_DIR/" 2>/dev/null || echo "Warning: index.php not found, skipping"
cp "$INSTALL_DIR/api_proxy.php" "$WEB_DIR/" 2>/dev/null || echo "Warning: api_proxy.php not found, skipping"

# Copy Python API script
cp "$INSTALL_DIR/laundry_locker_api.py" "$INSTALL_DIR/" 2>/dev/null || echo "Warning: laundry_locker_api.py not found, skipping"
chmod +x "$INSTALL_DIR/laundry_locker_api.py" 2>/dev/null || true

# Create systemd service for API server using virtual environment
print_header "Creating systemd service for API server"
cat > "$SYSTEMD_SERVICE" << EOF
[Unit]
Description=Laundry Locker API Server
After=network.target

[Service]
ExecStart=$VENV_DIR/bin/python $INSTALL_DIR/laundry_locker_api.py
WorkingDirectory=$INSTALL_DIR
StandardOutput=inherit
StandardError=inherit
Restart=always
User=pi

[Install]
WantedBy=multi-user.target
EOF

# Initialize the service (don't auto-start yet)
print_header "Initializing the service (not auto-starting)"
systemctl daemon-reload
systemctl disable laundry_locker_api.service

# Ask about kiosk mode setup
echo "Do you want to set up kiosk mode? (y/n)"
read setup_kiosk

if [ "$setup_kiosk" = "y" ] || [ "$setup_kiosk" = "Y" ]; then
    print_header "Setting up kiosk mode"
    
    # Create kiosk autostart script
    echo "Setting up autostart for kiosk mode..."
    mkdir -p /home/pi/.config/autostart
    cat > /home/pi/.config/autostart/kiosk.desktop << EOF
[Desktop Entry]
Type=Application
Name=Laundry Locker Kiosk
Exec=/home/pi/start_kiosk.sh
EOF

    # Create the kiosk start script
    cat > /home/pi/start_kiosk.sh << EOF
#!/bin/bash

# Hide the cursor
unclutter -idle 0.1 -root &

# Disable screen blanking
xset s off
xset -dpms
xset s noblank

# Start Chromium in kiosk mode
chromium-browser --noerrdialogs --kiosk --incognito http://localhost/laundry_locker/
EOF

    # Make the script executable
    chmod +x /home/pi/start_kiosk.sh

    # Disable screen timeout in the LXDE autostart
    echo "Disabling screen timeout..."
    mkdir -p /etc/xdg/lxsession/LXDE-pi
    cat > /etc/xdg/lxsession/LXDE-pi/autostart << EOF
@lxpanel --profile LXDE-pi
@pcmanfm --desktop --profile LXDE-pi
@xset s off
@xset -dpms
@xset s noblank
@/home/pi/start_kiosk.sh
EOF

    # Ask if they want kiosk mode enabled by default
    echo "Do you want to enable kiosk mode to start on boot? (y/n)"
    read enable_kiosk_default
    if [ "$enable_kiosk_default" != "y" ] && [ "$enable_kiosk_default" != "Y" ]; then
        mv /home/pi/.config/autostart/kiosk.desktop /home/pi/.config/autostart/kiosk.desktop.disabled
        echo "Kiosk mode has been installed but disabled. Use 'laundrylocker enable-kiosk' to enable it."
    fi
fi

# Create the command script
print_header "Creating control script"
create_command_script

print_header "Setup complete!"
echo "You can now control your Laundry Locker system with these commands:"
echo
echo "  laundrylocker start         - Start the API service"
echo "  laundrylocker stop          - Stop the API service"
echo "  laundrylocker restart       - Restart the API service"
echo "  laundrylocker status        - Check the status of the API service"
echo "  laundrylocker enable        - Enable API service to start on boot"
echo "  laundrylocker disable       - Disable API service from starting on boot"
if [ "$setup_kiosk" = "y" ] || [ "$setup_kiosk" = "Y" ]; then
    echo "  laundrylocker enable-kiosk  - Enable kiosk mode to start on boot"
    echo "  laundrylocker disable-kiosk - Disable kiosk mode from starting on boot"
fi
echo "  laundrylocker logs          - View API service logs"
echo
echo "The API service is currently DISABLED from auto-starting on boot."
echo "Use 'sudo laundrylocker enable' to enable it to start automatically on boot."
echo
echo "To reboot now, run: sudo reboot"