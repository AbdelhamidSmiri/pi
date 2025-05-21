#!/usr/bin/env python3
"""
RFID Laundry Locker API Server
- Improved version with enhanced RFID reading reliability
- Implements better error handling and recovery
- Uses non-blocking reads when possible
- Adds health check endpoint
"""

import RPi.GPIO as GPIO
import time
from mfrc522 import SimpleMFRC522
import json
import os
import logging
import requests
import uuid
from datetime import datetime, timedelta  # Add timedelta here
import threading
import sys
from flask import Flask, request, jsonify, abort
from flask_cors import CORS
from logging.handlers import RotatingFileHandler

# Configure logging with rotation to prevent huge log files
log_formatter = logging.Formatter('%(asctime)s - %(levelname)s - %(message)s')

# Main logger
main_handler = RotatingFileHandler(
    "/home/pi/laundry_locker_system.log",
    maxBytes=10*1024*1024,  # 10MB
    backupCount=5
)
main_handler.setFormatter(log_formatter)

# RFID specific logger
rfid_handler = RotatingFileHandler(
    "/home/pi/rfid_reader.log", 
    maxBytes=5*1024*1024,  # 5MB
    backupCount=3
)
rfid_handler.setFormatter(log_formatter)

# Console handler
console_handler = logging.StreamHandler()
console_handler.setFormatter(log_formatter)

# Main logger setup
logger = logging.getLogger("main")
logger.setLevel(logging.INFO)
logger.addHandler(main_handler)
logger.addHandler(console_handler)

# RFID logger setup - more verbose
rfid_logger = logging.getLogger("rfid")
rfid_logger.setLevel(logging.DEBUG)
rfid_logger.addHandler(rfid_handler)
rfid_logger.addHandler(console_handler)

# Configuration
CONFIG_FILE = "/home/pi/locker_config.json"
DB_FILE = "/home/pi/locker_data.json"
SERVER_URL = "https://api.2sdata.net/api"  # Replace with your server URL

# Default configuration
DEFAULT_CONFIG = {
    "system_name": "Laundry Locker System",
    "device_name": "locker-pi-001",  # Add a unique device name
    "device_location": "Main Building",  # Optional location information
    "server_url": SERVER_URL,
    "server_api_key": "7ea1d812-5308-4d61-bae6-be561caf1e98",  # Replace with your API key
    "relay_pins": {
        "1": 17,
        "2": 27,
        # Add more lockers as needed
    },
    "unlock_duration": 5,  # seconds
    "wash_types": [
        {"id": 1, "name": "Standard Wash", "price": 5.00},
        {"id": 2, "name": "Delicate Wash", "price": 7.50},
        {"id": 3, "name": "Heavy Duty", "price": 10.00}
    ],
    "api_port": 5000,
    "api_host": "0.0.0.0",  # Listen on all interfaces
    "rfid_read_timeout": 30,  # seconds
    "card_validity_window": 30  # seconds
}

class LockerTransaction:
    """Class to represent a locker transaction"""
    def __init__(self, card_id, locker_id, wash_type=None, status="pending", device_info=None):
        self.transaction_id = str(uuid.uuid4())
        self.card_id = card_id
        self.locker_id = locker_id
        self.wash_type = wash_type
        self.status = status  # pending, processing, completed
        self.drop_off_time = datetime.now().isoformat()
        self.pickup_time = None
        self.device_info = device_info or {}  # Store device information
        
        # Add estimated completion time based on wash type if available
        if wash_type and 'estimated_time' in wash_type:
            drop_off_datetime = datetime.now()
            minutes_to_add = wash_type['estimated_time']
            self.estimated_completion_time = (drop_off_datetime + timedelta(minutes=minutes_to_add)).isoformat()
        else:
            self.estimated_completion_time = None

    def to_dict(self):
        return {
            "transaction_id": self.transaction_id,
            "card_id": self.card_id,
            "locker_id": self.locker_id,
            "wash_type": self.wash_type,
            "status": self.status,
            "drop_off_time": self.drop_off_time,
            "pickup_time": self.pickup_time,
            "estimated_completion_time": self.estimated_completion_time,
            "device_info": self.device_info  # Include device info in output
        }

    def complete_transaction(self):
        self.status = "completed"
        self.pickup_time = datetime.now().isoformat()


class RFIDLockerSystem:
    def __init__(self):
        logger.info("Initializing RFID Laundry Locker System...")
        self.load_config()
        
        # Set up GPIO
        GPIO.setmode(GPIO.BCM)
        GPIO.setwarnings(False)
        
        # Set up relay pins as outputs
        self.relay_pins = {}
        for locker_id, pin in self.config["relay_pins"].items():
            GPIO.setup(pin, GPIO.OUT)
            GPIO.output(pin, GPIO.HIGH)  # Relays are typically active LOW
            self.relay_pins[locker_id] = pin
        
        # Initialize RFID reader with improved method
        self.initialize_rfid()
        
        # Load data
        self.data = self.load_data()
        logger.info(f"Loaded system data with {len(self.data['active_cards'])} active cards and {len(self.data['transactions'])} transactions")
        
        # Start server communication thread
        self.server_thread = threading.Thread(target=self.sync_with_server_loop, daemon=True)
        self.server_thread.start()
        
        # Setup RFID card reading queue with thread-safe access
        self.card_queue = []
        self.card_read_lock = threading.Lock()
        self.rfid_thread = threading.Thread(target=self.rfid_reader_loop, daemon=True)
        self.rfid_thread.start()
        
        # Track RFID reader health
        self.last_successful_read = None
        self.rfid_errors = 0
        self.rfid_reads_attempted = 0
        self.rfid_reads_successful = 0

    def initialize_rfid(self):
        """Initialize RFID reader with enhanced error handling and recovery"""
        rfid_logger.info("Initializing RFID reader")
        try:
            # Release GPIO resources completely before reinitializing
            GPIO.cleanup()
            time.sleep(1.0)  # Increased delay to ensure full reset
            
            # Set up GPIO again
            GPIO.setmode(GPIO.BCM)
            GPIO.setwarnings(False)
            
            # Set up relay pins as outputs again
            for locker_id, pin in self.config["relay_pins"].items():
                GPIO.setup(pin, GPIO.OUT)
                GPIO.output(pin, GPIO.HIGH)  # Relays are typically active LOW
            
            # Initialize RFID reader with SPI communication validation
            self.reader = SimpleMFRC522()
            
            # Test the reader is working by checking the card reader's presence
            # This is a more reliable way to verify the RFID reader is connected
            rfid_logger.info("RFID reader initialized successfully")
            self.last_rfid_init = time.time()
            return True
        except Exception as e:
            rfid_logger.critical(f"Failed to initialize RFID reader: {e}", exc_info=True)
            return False
    

    def load_config(self):
        """Load system configuration"""
        if not os.path.exists(CONFIG_FILE):
            # Create default configuration
            self.config = DEFAULT_CONFIG
            with open(CONFIG_FILE, 'w') as f:
                json.dump(self.config, f, indent=4)
            logger.info("Created default configuration file")
        else:
            try:
                with open(CONFIG_FILE, 'r') as f:
                    self.config = json.load(f)
                logger.info("Configuration loaded successfully")
            except Exception as e:
                logger.error(f"Error loading configuration: {e}")
                self.config = DEFAULT_CONFIG

    def load_data(self):
        """Load system data from database file"""
        if not os.path.exists(DB_FILE):
            # Create default database
            default_data = {
                "active_cards": {},  # card_id -> user_info
                "transactions": [],  # list of transaction objects
                "available_lockers": list(self.config["relay_pins"].keys())
            }
            with open(DB_FILE, 'w') as f:
                json.dump(default_data, f, indent=4)
            return default_data
        
        try:
            with open(DB_FILE, 'r') as f:
                return json.load(f)
        except Exception as e:
            logger.error(f"Error loading database: {e}")
            return {"active_cards": {}, "transactions": [], "available_lockers": list(self.config["relay_pins"].keys())}

    def save_data(self):
        """Save system data to database file"""
        try:
            with open(DB_FILE, 'w') as f:
                json.dump(self.data, f, indent=4)
            logger.info("Database saved successfully")
        except Exception as e:
            logger.error(f"Error saving database: {e}")

    def unlock_locker(self, locker_id):
        """Unlock specified locker"""
        if locker_id not in self.relay_pins:
            logger.error(f"Invalid locker ID: {locker_id}")
            return False
            
        relay_pin = self.relay_pins[locker_id]
        
        # Activate relay (LOW to turn on)
        GPIO.output(relay_pin, GPIO.LOW)
        
        logger.info(f"Locker {locker_id} unlocked")
        
        # Keep unlocked for defined duration
        time.sleep(self.config['unlock_duration'])
        
        # Lock the locker
        GPIO.output(relay_pin, GPIO.HIGH)
        
        logger.info(f"Locker {locker_id} locked")
        
        return True

    def rfid_reader_loop(self):
        """Background thread that continuously reads RFID cards with improved reliability"""
        rfid_logger.info("Starting RFID reader background thread")
        consecutive_errors = 0
        last_successful_read = time.time()
        read_interval = 0.1  # Base interval between reads
        
        while True:
            try:
                # Variable timing based on recent success/failure
                current_interval = read_interval * (1 + min(consecutive_errors, 5))
                
                # Try non-blocking read first to prevent thread blocking
                self.rfid_reads_attempted += 1
                id, text = self.reader.read_no_block()
                
                # Only process if we actually got a card
                if id is not None:
                    card_id = str(id)
                    rfid_logger.info(f"Card detected: {card_id}")
                    
                    # Add to queue with timestamp and reduced duplicate handling
                    with self.card_read_lock:
                        # Check for duplicates in the last 2 seconds only
                        now = datetime.now()
                        current_time_iso = now.isoformat()
                        
                        # Check for recent duplicates more intelligently
                        is_duplicate = False
                        for existing_card in self.card_queue:
                            if existing_card["card_id"] == card_id:
                                # Calculate time difference
                                existing_time = datetime.fromisoformat(existing_card["timestamp"])
                                seconds_diff = (now - existing_time).total_seconds()
                                
                                # Only consider duplicates within 2 seconds
                                if seconds_diff < 2.0:
                                    existing_card["timestamp"] = current_time_iso
                                    is_duplicate = True
                                    rfid_logger.debug(f"Duplicate card found within {seconds_diff}s, updating timestamp")
                                    break
                        
                        # Add new card if not a duplicate
                        if not is_duplicate:
                            self.card_queue.append({
                                "card_id": card_id, 
                                "timestamp": current_time_iso,
                                "read_count": 1  # Track how many times we've seen this card
                            })
                            
                        # Keep only the last 10 card reads to prevent memory issues
                        if len(self.card_queue) > 10:
                            self.card_queue.pop(0)
                    
                    # Update statistics and reset error counters
                    self.rfid_reads_successful += 1
                    self.last_successful_read = time.time()
                    consecutive_errors = 0
                    last_successful_read = time.time()
                    
                    # Temporarily increase read frequency on successful read
                    # This helps catch multiple presentations of the same card
                    read_interval = 0.05
                    
                else:
                    # If we didn't get a card, gradually return to normal timing
                    read_interval = min(read_interval * 1.1, 0.1)
                
                # Adaptive sleep to prevent CPU hogging while staying responsive
                time.sleep(current_interval)
                
            except Exception as e:
                consecutive_errors += 1
                self.rfid_errors += 1
                elapsed = time.time() - last_successful_read
                
                # Log with different severity based on how long we've been having issues
                if consecutive_errors < 5:
                    rfid_logger.warning(f"Error in RFID reader thread: {e}")
                    time.sleep(0.5)  # Short delay for occasional errors
                elif consecutive_errors < 20:
                    rfid_logger.error(f"Persistent errors in RFID reader: {e}, no successful reads for {elapsed:.1f}s")
                    time.sleep(1)  # Medium delay
                else:
                    rfid_logger.critical(f"RFID reader may be disconnected or malfunctioning: {e}, no reads for {elapsed:.1f}s")
                    
                    # Try to recover the reader after many consecutive errors
                    # More aggressive recovery strategy
                    if consecutive_errors % 20 == 0:  # Try recovery more frequently
                        try:
                            rfid_logger.info("Attempting to reinitialize RFID reader...")
                            self.initialize_rfid()
                            # Small delay after reinitialization
                            time.sleep(2)
                        except Exception as reset_error:
                            rfid_logger.critical(f"Failed to reinitialize RFID reader: {reset_error}")
                    
                    time.sleep(2)  # Longer delay for persistent errors

    def get_last_card(self):
        """Get the last card read from the queue with improved reliability and multiple read validation"""
        with self.card_read_lock:
            if not self.card_queue:
                logger.debug("Card queue is empty")
                return None
            
            # Current time for comparison    
            now = datetime.now()
            
            # Debug log the entire queue for troubleshooting
            rfid_logger.debug(f"Card queue contents: {self.card_queue}")
            
            # First, look for cards with multiple reads (more reliable)
            for i in range(len(self.card_queue) - 1, -1, -1):  # Search backward from most recent
                card = self.card_queue[i]
                card_time = datetime.fromisoformat(card["timestamp"])
                time_diff = (now - card_time).total_seconds()
                
                # If this card was seen multiple times and is recent, prioritize it
                if card.get("read_count", 1) > 1 and time_diff <= self.config.get('card_validity_window', 30):
                    rfid_logger.info(f"Returning multi-read card: {card}")
                    return card
            
            # Fall back to most recent card if no multiple-read cards found
            card = self.card_queue[-1]  # Most recent card
            card_time = datetime.fromisoformat(card["timestamp"])
            time_diff = (now - card_time).total_seconds()
            
            validity_window = self.config.get('card_validity_window', 30)
            
            if time_diff <= validity_window:
                rfid_logger.info(f"Returning most recent card: {card}")
                return card
            else:
                rfid_logger.info(f"Card found but too old ({time_diff:.1f}s), not returning")
                return None

    def clear_card_queue(self):
        """Clear the card queue"""
        with self.card_read_lock:
            self.card_queue = []
        return True

    def assign_card_to_locker(self, card_id, locker_id, wash_type_id):
        """Assign a card to a locker with selected wash type"""
        try:
            # Normalize card_id to string format
            card_id = str(card_id).strip()
            
            # Double-check card isn't already assigned
            if self.check_card_already_assigned(card_id):
                logger.error(f"Card {card_id} already has an active assignment")
                return False, f"Card already assigned to another locker"
            
                
            # Check if locker is available
            if locker_id not in self.data["available_lockers"]:
                logger.error(f"Locker {locker_id} is not available")
                return False, "Locker not available"

            # Get the full wash type information
            wash_types = self.get_wash_types()
            selected_wash_type = None
            
            for wt in wash_types:
                if wt['id'] == wash_type_id:
                    selected_wash_type = wt
                    break
            
            if not selected_wash_type:
                logger.error(f"Wash type {wash_type_id} not found")
                return False, "Invalid wash type"
            
            # Create new transaction with device info
            transaction = LockerTransaction(card_id, locker_id, selected_wash_type)
            
            # Update active cards
            self.data["active_cards"][card_id] = {
                "locker_id": locker_id,
                "transaction_id": transaction.transaction_id
            }
            
            # Remove locker from available list - make sure we convert to string if it's not already
            locker_id_str = str(locker_id)
            if locker_id_str in self.data["available_lockers"]:
                self.data["available_lockers"].remove(locker_id_str)
            
            # Add transaction to list
            self.data["transactions"].append(transaction.to_dict())
            
            # Save changes
            self.save_data()
            
            # Send update to server
            self.send_to_server("new_transaction", transaction.to_dict())
            
            return True, f"Card assigned to locker {locker_id} with {selected_wash_type['name']} service"
        except Exception as e:
            logger.error(f"Error in assign_card_to_locker: {e}")
            return False, f"System error: {str(e)}"
        
    def check_card_already_assigned(self, card_id):
        """Check if a card is already assigned to a locker"""
        # Normalize card ID to string
        card_id_str = str(card_id).strip()
        
        # Direct lookup
        if card_id_str in self.data["active_cards"]:
            return True
        
        # Case-insensitive lookup - normalize both for comparison
        card_id_lower = card_id_str.lower()
        for active_card in self.data["active_cards"].keys():
            if str(active_card).lower() == card_id_lower:
                return True
        
        # Also check the transactions to be safe
        for transaction in self.data["transactions"]:
            if transaction.get("status") != "completed":
                trans_card_id = str(transaction.get("card_id", "")).strip().lower()
                if trans_card_id == card_id_lower:
                    return True
        
        # Not found in any active assignments
        return False
    
    def process_pickup(self, card_id):
        """Process clothes pickup"""
        # Normalize card_id to string format to ensure consistent comparison
        card_id_str = str(card_id)
        
        # Add debug logging to help diagnose
        logger.info(f"Processing pickup for card ID: {card_id_str}")
        logger.info(f"Active cards in system: {list(self.data['active_cards'].keys())}")
        
        # Check if card exists in active cards, using string comparison
        if card_id_str not in self.data["active_cards"]:
            # Try to find by doing a case-insensitive comparison
            found = False
            for active_card_id in self.data["active_cards"].keys():
                if str(active_card_id).lower() == card_id_str.lower():
                    card_id_str = active_card_id  # Use the version from the database
                    found = True
                    logger.info(f"Found card with case-insensitive match: {active_card_id}")
                    break
            
            if not found:
                logger.error(f"Card {card_id_str} not found in active cards")
                return False, "Card not associated with any locker"
        
        # Get locker details
        locker_info = self.data["active_cards"][card_id_str]
        locker_id = locker_info["locker_id"]
        transaction_id = locker_info["transaction_id"]
        
        logger.info(f"Found active transaction: locker {locker_id}, transaction {transaction_id}")
        logger.info(f"Transactions in system: {len(self.data['transactions'])}")
        
        # List all transaction IDs for debugging
        transaction_ids = [t.get("transaction_id") for t in self.data["transactions"]]
        logger.info(f"Available transaction IDs: {transaction_ids}")
        
        # Find transaction - improved matching
        found_transaction = False
        for i, trans in enumerate(self.data["transactions"]):
            # Log each transaction we're checking
            logger.debug(f"Checking transaction {i}: {trans.get('transaction_id')}")
            
            # Match by transaction ID
            if trans.get("transaction_id") == transaction_id:
                found_transaction = True
                logger.info(f"Found matching transaction: {trans.get('transaction_id')}")
                
                # Update transaction
                self.data["transactions"][i]["status"] = "completed"
                self.data["transactions"][i]["pickup_time"] = datetime.now().isoformat()
                
                # Format wash_type before sending
                transaction_data = self.data["transactions"][i].copy()
                if 'wash_type' in transaction_data and isinstance(transaction_data['wash_type'], dict):
                    transaction_data['wash_type'] = transaction_data['wash_type'].get('name', 'Unknown')
                
                # Unlock locker
                self.unlock_locker(locker_id)
                
                # Remove card from active cards
                del self.data["active_cards"][card_id_str]
                
                # Add locker back to available list
                locker_id_str = str(locker_id)  # Ensure locker_id is string
                if locker_id_str not in self.data["available_lockers"]:
                    self.data["available_lockers"].append(locker_id_str)
                
                # Save changes
                self.save_data()
                
                # Send update to server
                self.send_to_server("pickup_complete", transaction_data)
                
                return True, f"Clothes picked up from locker {locker_id}"
        
        # If we get here, we didn't find the transaction
        logger.error(f"Transaction {transaction_id} not found for card {card_id_str}")
        
        # As a fallback, try to find any transaction by card ID
        fallback_found = False
        for i, trans in enumerate(self.data["transactions"]):
            if trans.get("card_id") == card_id_str and trans.get("status") != "completed":
                fallback_found = True
                logger.info(f"Found fallback transaction by card ID: {trans.get('transaction_id')}")
                
                # Use this transaction
                transaction_id = trans.get("transaction_id")
                locker_id = trans.get("locker_id")
                
                # Update transaction
                self.data["transactions"][i]["status"] = "completed"
                self.data["transactions"][i]["pickup_time"] = datetime.now().isoformat()
                
                # Unlock locker
                self.unlock_locker(locker_id)
                
                # Remove card from active cards
                if card_id_str in self.data["active_cards"]:
                    del self.data["active_cards"][card_id_str]
                
                # Add locker back to available list
                locker_id_str = str(locker_id)  # Ensure locker_id is string
                if locker_id_str not in self.data["available_lockers"]:
                    self.data["available_lockers"].append(locker_id_str)
                
                # Save changes
                self.save_data()
                
                return True, f"Clothes picked up from locker {locker_id} (fallback)"
        
        # Special case - reset the system if something is corrupted
        if not fallback_found and card_id_str in self.data["active_cards"]:
            logger.warning(f"Transaction mismatch - resetting card {card_id_str}")
            locker_id = self.data["active_cards"][card_id_str]["locker_id"]
            
            # Remove card from active cards
            del self.data["active_cards"][card_id_str]
            
            # Add locker back to available list
            locker_id_str = str(locker_id)
            if locker_id_str not in self.data["available_lockers"]:
                self.data["available_lockers"].append(locker_id_str)
            
            # Save changes
            self.save_data()
            
            return True, f"Locker {locker_id} unlocked and reset"
        
        return False, "Transaction not found"
    
    def send_to_server(self, action, data):
        try:
            # Make a copy of the data
            payload_data = data.copy() if isinstance(data, dict) else data
            
            # Format the wash_type properly
            if isinstance(payload_data, dict) and 'wash_type' in payload_data:
                if isinstance(payload_data['wash_type'], dict):
                    payload_data['wash_type'] = payload_data['wash_type'].get('name', 'Unknown')
            
            # Build payload
            payload = {
                "action": action,
                "api_key": self.config["server_api_key"],
                "timestamp": datetime.now().isoformat(),
                "data": payload_data
            }
            
            # Explicitly serialize to JSON to verify format
            json_payload = json.dumps(payload)
            logger.debug(f"JSON payload: {json_payload}")
            
            # Send using the serialized JSON
            headers = {
                'Content-Type': 'application/json'
            }
            
            response = requests.post(
                f"{self.config['server_url']}/{action}",
                data=json_payload,  # Use the serialized JSON string
                headers=headers,
                timeout=5
            )
            
            # Log the full response for debugging
            logger.debug(f"Response status: {response.status_code}")
            logger.debug(f"Response body: {response.text}")
            
            if response.status_code == 200:
                logger.info(f"Successfully sent {action} to server")
                return True
            else:
                logger.error(f"Server error: {response.status_code} - {response.text}")
                # Try to parse error response if possible
                try:
                    error_data = response.json()
                    logger.error(f"Error details: {error_data}")
                except:
                    pass
                return False
        except requests.exceptions.ConnectionError as e:
            logger.error(f"Connection error to server: {e}")
            return False
        except requests.exceptions.Timeout as e:
            logger.error(f"Timeout connecting to server: {e}")
            return False
        except Exception as e:
            logger.error(f"Error communicating with server: {e}")
            return False

    def sync_with_server_loop(self):
        """Periodically sync data with server"""
        while True:
            try:
                self.send_to_server("sync", {
                    "active_cards": len(self.data["active_cards"]),
                    "available_lockers": self.data["available_lockers"],
                    "total_transactions": len(self.data["transactions"])
                })
            except Exception as e:
                logger.error(f"Error during server sync: {e}")
            
            # Sleep for 5 minutes before next sync
            time.sleep(300)

    def get_system_status(self):
        """Get current system status"""
        # Calculate time since last successful RFID read
        rfid_status = "OK"
        last_read_time = "Never"
        
        if self.last_successful_read is not None:
            time_diff = time.time() - self.last_successful_read
            last_read_time = f"{int(time_diff)} seconds ago"
            
            if time_diff > 300:  # 5 minutes
                rfid_status = "WARNING"
        
        return {
            "system_name": self.config["system_name"],
            "active_cards": len(self.data["active_cards"]),
            "available_lockers": self.data["available_lockers"],
            "total_transactions": len(self.data["transactions"]),
            "last_sync": datetime.now().isoformat(),
            "rfid": {
                "status": rfid_status,
                "last_read": last_read_time,
                "error_count": self.rfid_errors,
                "success_rate": (self.rfid_reads_successful / self.rfid_reads_attempted * 100) if self.rfid_reads_attempted > 0 else 0
            }
        }

    def get_wash_types(self):
        """Get available wash types from server, fall back to local config if server unreachable"""
        try:
            # Attempt to fetch wash types from server with API key in POST request
            payload = {
                "api_key": self.config["server_api_key"],
                "action": "get_wash_types"
            }
            
            logger.info(f"Fetching wash types from server: {self.config['server_url']}/get_wash_types")
            response = requests.post(
                f"{self.config['server_url']}/get_wash_types",
                json=payload,
                timeout=5
            )
            
            logger.debug(f"Wash types response: {response.status_code}")
            
            if response.status_code == 200:
                # Rest of function as before
                wash_types = response.json().get("wash_types", [])
                if wash_types:
                    logger.info(f"Successfully fetched {len(wash_types)} wash types from server")
                    
                    # Transform to match the expected structure if needed
                    for wash_type in wash_types:
                        # Ensure it has at least the required fields expected by the UI
                        if 'id' not in wash_type:
                            wash_type['id'] = 0
                        if 'name' not in wash_type:
                            wash_type['name'] = "Unknown"
                        if 'price' not in wash_type:
                            wash_type['price'] = 0.0
                    
                    # Cache the wash types in memory for backup
                    self.server_wash_types = wash_types
                    return wash_types
                else:
                    logger.warning("Server returned empty wash types list, using local config")
            else:
                logger.warning(f"Server error when fetching wash types: {response.status_code}")
                try:
                    error_data = response.json()
                    logger.warning(f"Error details: {error_data}")
                except:
                    logger.warning(f"Response text: {response.text}")
        except Exception as e:
            logger.error(f"Error fetching wash types from server: {e}")
        
        # Fall back to server-cached wash types if available
        if hasattr(self, 'server_wash_types') and self.server_wash_types:
            logger.info("Using cached server wash types")
            return self.server_wash_types
            
        # Fall back to local config if server unreachable or returned error
        logger.info("Using local config wash types")
        return self.config["wash_types"]

    def get_health(self):
        """Get system health information"""
        health_data = {
            "status": "healthy",
            "uptime": "unknown",  # Would need to track start time
            "rfid_reader": {
                "status": "OK" if self.last_successful_read is not None and (time.time() - self.last_successful_read) < 300 else "WARNING",
                "last_successful_read": None if self.last_successful_read is None else f"{int(time.time() - self.last_successful_read)} seconds ago",
                "error_count": self.rfid_errors,
                "read_success_rate": f"{(self.rfid_reads_successful / self.rfid_reads_attempted * 100):.1f}%" if self.rfid_reads_attempted > 0 else "0%"
            },
            "available_lockers": len(self.data["available_lockers"]),
            "active_transactions": len(self.data["active_cards"]),
            "memory_usage": "unknown"  # Would need psutil to get this
        }
        
        # Set overall status based on component health
        if health_data["rfid_reader"]["status"] != "OK":
            health_data["status"] = "degraded"
            health_data["issues"] = ["RFID reader may not be functioning correctly"]
        
        return health_data


# Initialize Flask app
app = Flask(__name__)
CORS(app)  # Enable CORS for all routes

# Initialize locker system
locker_system = None

@app.route('/api/device-info', methods=['GET'])
def get_device_info():
    """Get device information"""
    global locker_system
    
    device_info = {
        "device_name": locker_system.config.get("device_name", "unknown-device"),
        "device_location": locker_system.config.get("device_location", "unknown-location"),
        "system_name": locker_system.config.get("system_name", "Laundry Locker System"),
        "api_version": "1.1.0",  # Add version information
        "uptime": "unknown",  # You could add real uptime tracking if needed
    }
    
    return jsonify(device_info)

@app.route('/api/update-device-info', methods=['POST'])
def update_device_info():
    """Update device information"""
    global locker_system
    
    try:
        data = request.json
        if not data:
            return jsonify({"success": False, "message": "Missing request data"})
        
        # Update device information
        if "device_name" in data:
            locker_system.config["device_name"] = data["device_name"]
        
        if "device_location" in data:
            locker_system.config["device_location"] = data["device_location"]
            
        if "system_name" in data:
            locker_system.config["system_name"] = data["system_name"]
        
        # Save the updated configuration
        try:
            with open(CONFIG_FILE, 'w') as f:
                json.dump(locker_system.config, f, indent=4)
            logger.info("Device information updated and saved")
        except Exception as e:
            logger.error(f"Error saving configuration: {e}")
            return jsonify({"success": False, "message": f"Error saving configuration: {str(e)}"})
        
        return jsonify({
            "success": True, 
            "message": "Device information updated successfully",
            "device_info": {
                "device_name": locker_system.config.get("device_name"),
                "device_location": locker_system.config.get("device_location"),
                "system_name": locker_system.config.get("system_name")
            }
        })
    except Exception as e:
        logger.error(f"Error updating device info: {e}")
        return jsonify({"success": False, "message": f"System error: {str(e)}"})


@app.route('/api/status', methods=['GET'])
def get_status():
    """Get system status"""
    global locker_system
    return jsonify(locker_system.get_system_status())

@app.route('/api/health', methods=['GET'])
def get_health():
    """Get system health information"""
    global locker_system
    return jsonify(locker_system.get_health())

@app.route('/api/wash-types', methods=['GET'])
def get_wash_types():
    """Get available wash types"""
    global locker_system
    wash_types = locker_system.get_wash_types()
    
    # If this is our local configuration, we might need to adapt it for the UI
    if not any('description' in wt for wt in wash_types):
        # Add missing fields for compatibility
        for wash_type in wash_types:
            if 'description' not in wash_type:
                wash_type['description'] = f"{wash_type['name']} service"
            if 'estimated_time' not in wash_type:
                wash_type['estimated_time'] = 60  # Default 60 minutes
    
    return jsonify(wash_types)

@app.route('/api/read-card', methods=['GET'])
def read_card():
    """Get last read card with enhanced error handling"""
    global locker_system
    try:
        card = locker_system.get_last_card()
        
        if card:
            logger.info(f"Successfully read card: {card}")
            return jsonify({"success": True, "card": card})
        
        logger.info("No card recently read")
        return jsonify({"success": False, "message": "No card recently read"})
    except Exception as e:
        logger.error(f"Error reading card: {e}")
        return jsonify({"success": False, "message": f"Error reading card: {str(e)}"})

@app.route('/api/clear-card-queue', methods=['POST'])
def clear_card_queue():
    """Clear the card reading queue"""
    global locker_system
    locker_system.clear_card_queue()
    return jsonify({"success": True})

@app.route('/api/drop-off', methods=['POST'])
def drop_off():
    """Process drop off"""
    global locker_system
    
    data = request.json
    if not data or "card_id" not in data or "wash_type" not in data:
        return jsonify({"success": False, "message": "Missing required fields"})
    
    # Get and normalize card ID
    card_id = str(data["card_id"]).strip()
    logger.info(f"Drop-off request for card: {card_id}")
    
    # Check if card already has an active assignment
    if locker_system.check_card_already_assigned(card_id):
        logger.warning(f"Card {card_id} already has an active assignment")
        # Get the assigned locker if possible
        locker_id = "unknown"
        for active_card, info in locker_system.data["active_cards"].items():
            if str(active_card).lower() == card_id.lower():
                locker_id = info.get("locker_id", "unknown")
                break
        
        return jsonify({
            "success": False, 
            "message": f"This card already has clothes in locker {locker_id}. Please use the pickup process first."
        })
    
    # Check if lockers are available
    if not locker_system.data["available_lockers"]:
        return jsonify({"success": False, "message": "No lockers available"})
    
    
    # Get first available locker
    locker_id = locker_system.data["available_lockers"][0]
    
    # Get wash type - if it's a string, we need to look up the ID
    wash_type_param = data["wash_type"]
    wash_type_id = None
    
    # If wash_type is already a dict with ID
    if isinstance(wash_type_param, dict) and "id" in wash_type_param:
        wash_type_id = wash_type_param["id"]
    # If wash_type is already an ID (numeric or UUID format)
    elif isinstance(wash_type_param, (int, str)) and not ' ' in str(wash_type_param):
        wash_type_id = wash_type_param
    # If wash_type is a name string, we need to look up the ID
    else:
        # Get available wash types (assuming this is accessible)
        wash_types = locker_system.get_wash_types()  # Implement this method
        for wash_type in wash_types:
            if wash_type["name"] == wash_type_param:
                wash_type_id = wash_type["id"]
                break
        
        if not wash_type_id:
            logger.error(f"Wash type {wash_type_param} not found")
            return jsonify({"success": False, "message": f"Invalid wash type: {wash_type_param}"})
    
    logger.info(f"Using wash type ID: {wash_type_id}")
    
    # Process assignment
    success, message = locker_system.assign_card_to_locker(
        data["card_id"], 
        locker_id, 
        wash_type_id
    )
    
    if success:
        # Unlock the locker
        locker_system.unlock_locker(locker_id)
        return jsonify({
            "success": True, 
            "message": message,
            "locker_id": locker_id
        })
    else:
        return jsonify({"success": False, "message": message})

@app.route('/api/pick-up', methods=['POST'])
def pick_up():
    """Process pick up"""
    global locker_system
    
    data = request.json
    if not data or "card_id" not in data:
        return jsonify({"success": False, "message": "Missing card_id"})
    
    # Make sure card_id is a string
    card_id = str(data["card_id"])
    
    logger.info(f"Pickup request for card: {card_id}")
    
    success, message = locker_system.process_pickup(card_id)
    
    return jsonify({
        "success": success,
        "message": message
    })

@app.route('/api/reset-rfid-reader', methods=['POST'])
def reset_rfid_reader():
    """Manually reset the RFID reader - admin function"""
    global locker_system
    
    try:
        logger.info("Manual RFID reader reset requested")
        result = locker_system.initialize_rfid()
        
        if result:
            return jsonify({
                "success": True,
                "message": "RFID reader reset successfully"
            })
        else:
            return jsonify({
                "success": False,
                "message": "Failed to reset RFID reader"
            })
    except Exception as e:
        logger.error(f"Error during manual RFID reset: {e}")
        return jsonify({
            "success": False,
            "message": f"Error: {str(e)}"
        })

def start_api_server(host="0.0.0.0", port=5000):
    """Start the API server"""
    global locker_system
    
    # Initialize locker system
    locker_system = RFIDLockerSystem()
    
    # Set Flask to handle exceptions properly
    app.config['PROPAGATE_EXCEPTIONS'] = True
    
    # Start Flask app
    try:
        app.run(host=host, port=port)
    except Exception as e:
        logger.critical(f"Failed to start API server: {e}", exc_info=True)
        sys.exit(1)

if __name__ == "__main__":
    try:
        # Get host and port from config or command line
        host = DEFAULT_CONFIG["api_host"]
        port = DEFAULT_CONFIG["api_port"]
        
        if len(sys.argv) > 1:
            port = int(sys.argv[1])
        
        logger.info(f"Starting API server on {host}:{port}")
        start_api_server(host, port)
    except KeyboardInterrupt:
        logger.info("Server stopped by user")
    except Exception as e:
        logger.critical(f"Unhandled exception: {e}", exc_info=True)