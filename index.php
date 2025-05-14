<?php
// Configuration
$api_url = "http://localhost:5000/api";
$system_name = "Laundry Locker System";
$refresh_interval = 1000; // 1 second (reduced from 2 seconds for more responsive UI)

// Function to call API
function callApi($endpoint, $method = 'GET', $data = null)
{
    global $api_url;

    $curl = curl_init();

    $options = [
        CURLOPT_URL => $api_url . $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => $method
    ];

    if ($data && ($method == 'POST' || $method == 'PUT')) {
        $options[CURLOPT_POSTFIELDS] = json_encode($data);
        $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }

    curl_setopt_array($curl, $options);

    $response = curl_exec($curl);
    $error = curl_error($curl);

    curl_close($curl);

    if ($error) {
        return ['success' => false, 'message' => $error];
    }

    return json_decode($response, true);
}

// Get system status
$system_status = callApi('/status');
if (isset($system_status['system_name'])) {
    $system_name = $system_status['system_name'];
}

// Get wash types
$wash_types = callApi('/wash-types');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($system_name) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 0;
            margin: 0;
            background-color: #f5f5f5;
            touch-action: manipulation;
            overflow: hidden;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .header {
            background-color: #007bff;
            color: #fff;
            padding: 15px 20px;
            text-align: center;
            font-size: 24px;
            font-weight: bold;
        }

        .content {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 20px;
            overflow-y: auto;
        }

        .btn-large {
            padding: 20px;
            font-size: 22px;
            margin-bottom: 20px;
        }

        .status-area {
            margin-top: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 5px;
            border: 1px solid #dee2e6;
            min-height: 100px;
            text-align: center;
            font-size: 20px;
        }

        .footer {
            padding: 10px 20px;
            text-align: center;
            background-color: #f8f9fa;
            border-top: 1px solid #dee2e6;
            font-size: 14px;
        }

        .tabs {
            display: flex;
            margin-bottom: 20px;
        }

        .tab {
            flex: 1;
            text-align: center;
            padding: 15px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            cursor: pointer;
            font-size: 20px;
            font-weight: bold;
        }

        .tab.active {
            background-color: #007bff;
            color: #fff;
            border-color: #007bff;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .wash-type-option {
            padding: 15px;
            margin-bottom: 10px;
            border: 2px solid #dee2e6;
            border-radius: 5px;
            cursor: pointer;
            font-size: 18px;
            transition: all 0.2s;
        }

        .wash-type-option:hover {
            background-color: #e9ecef;
        }

        .wash-type-option.selected {
            background-color: #d1e7ff;
            border-color: #007bff;
        }

        .price {
            float: right;
            font-weight: bold;
        }

        #rfidStatus,
        #pickupRfidStatus {
            font-size: 20px;
            font-weight: bold;
            margin-top: 10px;
        }

        .loading-spinner {
            display: inline-block;
            width: 2rem;
            height: 2rem;
            border: 0.25rem solid #f3f3f3;
            border-top: 0.25rem solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 10px;
            vertical-align: middle;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .locker-result {
            font-size: 28px;
            font-weight: bold;
            margin: 20px 0;
            text-align: center;
        }

        #statusMessage,
        #pickupStatusMessage {
            min-height: 50px;
        }

        .progress {
            height: 10px;
            margin-top: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            overflow: hidden;
        }

        .progress-bar {
            background-color: #007bff;
            transition: width 1s;
        }

        .card-reader-area {
            margin-top: 15px;
            padding: 15px;
            border: 1px dashed #007bff;
            background-color: #f0f7ff;
            border-radius: 5px;
            text-align: center;
        }

        .alert {
            margin-top: 15px;
        }

        .system-status-indicator {
            padding: 5px 10px;
            margin-bottom: 10px;
            font-size: 14px;
            border-radius: 15px;
            display: inline-block;
        }

        .system-status-indicator.online {
            background-color: #d4edda;
            color: #155724;
        }

        .system-status-indicator.error {
            background-color: #f8d7da;
            color: #721c24;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <?= htmlspecialchars($system_name) ?>
            <div id="systemStatusIndicator" class="system-status-indicator online">System Ready</div>
        </div>

        <div class="tabs">
            <div class="tab active" data-tab="dropoff">Drop Off</div>
            <div class="tab" data-tab="pickup">Pick Up</div>
            <?php if (isset($_GET['admin'])): ?>
                <div class="tab" data-tab="admin">Admin</div>
                <div class="tab" data-tab="device">Device</div>
            <?php endif; ?>
        </div>

        <div class="content">
            <!-- Drop Off Tab -->
            <div class="tab-content active" id="dropoff-content">
                <h2>Select Wash Type:</h2>

                <div class="wash-types">
                    <?php if (isset($wash_types) && is_array($wash_types)): ?>
                        <?php foreach ($wash_types as $wash): ?>
                            <div class="wash-type-option" data-wash-id="<?= $wash['id'] ?>" data-wash-name="<?= htmlspecialchars($wash['name']) ?>">
                                <?= htmlspecialchars($wash['name']) ?>
                                <span class="price">$<?= number_format($wash['price'], 2) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            Unable to load wash types. Please check API connection.
                        </div>
                    <?php endif; ?>
                </div>

                <button id="startDropOff" class="btn btn-primary btn-large btn-block" disabled>
                    Start Drop Off Process
                </button>

                <div class="status-area">
                    <div id="statusMessage">Please select a wash type to continue.</div>
                    <div id="rfidStatus"></div>
                </div>
            </div>

            <!-- Pick Up Tab -->
            <div class="tab-content" id="pickup-content">
                <h2>Pick Up Your Clothes</h2>

                <button id="startPickUp" class="btn btn-success btn-large btn-block">
                    Start Pick Up Process
                </button>

                <div class="status-area">
                    <div id="pickupStatusMessage">Please tap the button above to begin.</div>
                    <div id="pickupRfidStatus"></div>
                </div>
            </div>

            <?php if (isset($_GET['admin'])): ?>
                <!-- Admin Tab -->
                <div class="tab-content" id="admin-content">
                    <h2>System Administration</h2>

                    <button id="refreshStatus" class="btn btn-info btn-block mb-3">
                        Refresh System Status
                    </button>

                    <button id="testRfidReader" class="btn btn-warning btn-block mb-3">
                        Test RFID Reader
                    </button>

                    <div class="status-area">
                        <h4>System Status</h4>
                        <?php if (isset($system_status) && is_array($system_status)): ?>
                            <div>Available Lockers: <?= implode(', ', $system_status['available_lockers'] ?? []) ?></div>
                            <div>Active Cards: <?= $system_status['active_cards'] ?? 0 ?></div>
                            <div>Total Transactions: <?= $system_status['total_transactions'] ?? 0 ?></div>
                        <?php else: ?>
                            <div>Unable to load system status.</div>
                        <?php endif; ?>
                    </div>

                    <div class="card mt-3">
                        <div class="card-header">
                            RFID Reader Test
                        </div>
                        <div class="card-body">
                            <div id="rfidTestStatus">Click "Test RFID Reader" to begin the test.</div>
                        </div>
                    </div>
                </div>
                <!-- Device Management Tab -->
                <div class="tab-content" id="device-content">
                    <h2>Device Management</h2>

                    <div class="card mb-3">
                        <div class="card-header">
                            Device Information
                        </div>
                        <div class="card-body">
                            <div id="deviceInfoLoading">Loading device information...</div>
                            <div id="deviceInfoContent" style="display:none;">
                                <form id="deviceInfoForm">
                                    <div class="mb-3">
                                        <label for="deviceName" class="form-label">Device Name</label>
                                        <input type="text" class="form-control" id="deviceName" name="device_name">
                                    </div>
                                    <div class="mb-3">
                                        <label for="deviceLocation" class="form-label">Device Location</label>
                                        <input type="text" class="form-control" id="deviceLocation" name="device_location">
                                    </div>
                                    <div class="mb-3">
                                        <label for="systemName" class="form-label">System Name</label>
                                        <input type="text" class="form-control" id="systemName" name="system_name">
                                    </div>
                                    <button type="submit" class="btn btn-primary">Update Device Information</button>
                                </form>
                                <div id="deviceUpdateStatus" class="mt-3"></div>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-header">
                            Recent Transactions
                        </div>
                        <div class="card-body">
                            <div id="transactionsLoading">Loading recent transactions...</div>
                            <div id="transactionsContent" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="footer">
            &copy; <?= date('Y') ?> Laundry Locker System
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            let selectedWashType = null;
            let cardPollingInterval = null;
            let isProcessing = false;
            let systemHealthInterval = null;

            // System health check interval
            systemHealthInterval = setInterval(checkSystemHealth, 60000); // Check every minute
            checkSystemHealth(); // Check immediately on load

            function checkSystemHealth() {
                $.ajax({
                    url: 'api_proxy.php?endpoint=status',
                    type: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if (response && response.system_name) {
                            $('#systemStatusIndicator').text('System Ready').removeClass('error').addClass('online');
                        } else {
                            $('#systemStatusIndicator').text('Connection Error').removeClass('online').addClass('error');
                        }
                    },
                    error: function() {
                        $('#systemStatusIndicator').text('Connection Error').removeClass('online').addClass('error');
                    }
                });
            }

            // Enhanced card polling function
            function enhancedCardPolling(statusElement, messageElement, onCardDetected) {
                let attempts = 0;
                const maxAttempts = 30; // Try for 30 seconds (30 attempts at 1-second intervals)
                let pollingInterval;

                // Reset status elements
                $(statusElement).html('<div class="loading-spinner"></div> Please scan your RFID card...');
                $(messageElement).html('<div class="card-reader-area">Please place your card on the reader</div>');

                // Create a progress bar for visual feedback
                const progressBar = $('<div class="progress"><div class="progress-bar" role="progressbar" style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div></div>');
                $(statusElement).after(progressBar);

                // Start polling for card
                pollingInterval = setInterval(function() {
                    attempts++;

                    // Update progress bar
                    const progress = (attempts / maxAttempts) * 100;
                    progressBar.find('.progress-bar').css('width', progress + '%').attr('aria-valuenow', progress);

                    // Check for card
                    $.ajax({
                        url: 'api_proxy.php?endpoint=read-card',
                        type: 'GET',
                        dataType: 'json',
                        timeout: 3000, // 3 second timeout
                        success: function(response) {
                            if (response.success && response.card) {
                                // Card detected
                                clearInterval(pollingInterval);
                                progressBar.remove();

                                $(statusElement).html('<div class="loading-spinner"></div> Card detected, processing...');
                                $(messageElement).html('<div class="alert alert-info">Card detected! Processing your request...</div>');

                                // Call the callback function with the card data
                                onCardDetected(response.card);

                                // Clear card queue to prevent duplicate reads
                                $.ajax({
                                    url: 'api_proxy.php?endpoint=clear-card-queue',
                                    type: 'POST'
                                });
                            } else if (attempts >= maxAttempts) {
                                // No card detected after maximum attempts
                                clearInterval(pollingInterval);
                                progressBar.remove();

                                $(statusElement).html('');
                                $(messageElement).html(`
                                    <div class="alert alert-warning">
                                        No card detected after ${maxAttempts} seconds. 
                                        <button class="btn btn-primary btn-sm ms-2" id="retryCardScan">Try Again</button>
                                    </div>
                                `);

                                // Set up retry button
                                $('#retryCardScan').click(function() {
                                    enhancedCardPolling(statusElement, messageElement, onCardDetected);
                                });
                            }
                        },
                        error: function(xhr, status, error) {
                            // Handle connection errors
                            if (attempts < maxAttempts) {
                                // Log the error but continue trying
                                console.error(`Card polling error (attempt ${attempts}): ${error}`);
                                $(messageElement).html(`
                                    <div class="alert alert-warning">
                                        Connection issue, retrying... (${maxAttempts - attempts} attempts left)
                                    </div>
                                `);
                            } else {
                                // Stop after max attempts
                                clearInterval(pollingInterval);
                                progressBar.remove();

                                $(statusElement).html('');
                                $(messageElement).html(`
                                    <div class="alert alert-danger">
                                        Error connecting to system. 
                                        <button class="btn btn-primary btn-sm ms-2" id="retryCardScan">Try Again</button>
                                    </div>
                                `);

                                // Set up retry button
                                $('#retryCardScan').click(function() {
                                    enhancedCardPolling(statusElement, messageElement, onCardDetected);
                                });
                            }
                        }
                    });
                }, <?= $refresh_interval ?>);

                // Return a cancel function
                return function cancelPolling() {
                    if (pollingInterval) {
                        clearInterval(pollingInterval);
                        progressBar.remove();
                    }
                };
            }

            // Tab switching
            $('.tab').click(function() {
                $('.tab').removeClass('active');
                $(this).addClass('active');

                const tabId = $(this).data('tab');

                $('.tab-content').removeClass('active');
                $(`#${tabId}-content`).addClass('active');

                // Stop any active card polling
                if (cardPollingInterval) {
                    clearInterval(cardPollingInterval);
                    cardPollingInterval = null;
                }

                // Clear status messages
                $('#rfidStatus').html('');
                $('#pickupRfidStatus').html('');
                $('#statusMessage').text('Please select a wash type to continue.');
                $('#pickupStatusMessage').text('Please tap the button above to begin.');
            });

            // Wash type selection
            $('.wash-type-option').click(function() {
                $('.wash-type-option').removeClass('selected');
                $(this).addClass('selected');

                selectedWashType = {
                    id: $(this).data('wash-id'),
                    name: $(this).data('wash-name')
                };

                $('#startDropOff').prop('disabled', false);
                $('#statusMessage').text(`Selected: ${selectedWashType.name}`);
            });

            // Start Drop Off Process
            $('#startDropOff').click(function() {
                if (isProcessing) return;

                if (!selectedWashType) {
                    $('#statusMessage').text('Please select a wash type first.');
                    return;
                }

                isProcessing = true;

                // Start enhanced card polling
                const cancelPolling = enhancedCardPolling('#rfidStatus', '#statusMessage', function(card) {
                    // Process drop off with the detected card
                    $.ajax({
                        url: 'api_proxy.php?endpoint=drop-off',
                        type: 'POST',
                        dataType: 'json',
                        contentType: 'application/json',
                        data: JSON.stringify({
                            card_id: card.card_id,
                            wash_type: selectedWashType.name
                        }),
                        success: function(result) {
                            if (result.success) {
                                $('#rfidStatus').html('');
                                $('#statusMessage').html(`
                                    <div class="alert alert-success">
                                        Success! ${result.message}
                                    </div>
                                    <div class="locker-result">
                                        Locker #${result.locker_id}
                                    </div>
                                    <div>
                                        Please place your clothes in the locker.
                                    </div>
                                `);
                            } else {
                                $('#rfidStatus').html('');
                                $('#statusMessage').html(`
                                    <div class="alert alert-danger">
                                        Error: ${result.message}
                                    </div>
                                    <button class="btn btn-primary mt-3" id="retryProcess">Try Again</button>
                                `);

                                $('#retryProcess').click(function() {
                                    isProcessing = false;
                                    $('#startDropOff').click();
                                });
                            }
                            isProcessing = false;
                        },
                        error: function() {
                            $('#rfidStatus').html('');
                            $('#statusMessage').html(`
                                <div class="alert alert-danger">
                                    Error: Could not connect to system
                                </div>
                                <button class="btn btn-primary mt-3" id="retryProcess">Try Again</button>
                            `);

                            $('#retryProcess').click(function() {
                                isProcessing = false;
                                $('#startDropOff').click();
                            });

                            isProcessing = false;
                        }
                    });
                });

                // Add cancel button
                $('#rfidStatus').append('<button class="btn btn-sm btn-outline-secondary mt-2" id="cancelCardScan">Cancel</button>');
                $('#cancelCardScan').click(function() {
                    cancelPolling();
                    $('#rfidStatus').html('');
                    $('#statusMessage').text('Card scanning cancelled. Select a wash type and try again.');
                    isProcessing = false;
                });
            });

            // Start Pick Up Process
            $('#startPickUp').click(function() {
                if (isProcessing) return;

                isProcessing = true;

                // Start enhanced card polling for pickup
                const cancelPolling = enhancedCardPolling('#pickupRfidStatus', '#pickupStatusMessage', function(card) {
                    // Process pickup with the detected card
                    $.ajax({
                        url: 'api_proxy.php?endpoint=pick-up',
                        type: 'POST',
                        dataType: 'json',
                        contentType: 'application/json',
                        data: JSON.stringify({
                            card_id: card.card_id
                        }),
                        success: function(result) {
                            if (result.success) {
                                $('#pickupRfidStatus').html('');
                                $('#pickupStatusMessage').html(`
                                    <div class="alert alert-success">
                                        Success! ${result.message}
                                    </div>
                                    <div>
                                        You may now retrieve your clothes.
                                    </div>
                                `);
                            } else {
                                $('#pickupRfidStatus').html('');
                                $('#pickupStatusMessage').html(`
                                    <div class="alert alert-danger">
                                        Error: ${result.message}
                                    </div>
                                    <button class="btn btn-primary mt-3" id="retryPickupProcess">Try Again</button>
                                `);

                                $('#retryPickupProcess').click(function() {
                                    isProcessing = false;
                                    $('#startPickUp').click();
                                });
                            }
                            isProcessing = false;
                        },
                        error: function() {
                            $('#pickupRfidStatus').html('');
                            $('#pickupStatusMessage').html(`
                                <div class="alert alert-danger">
                                    Error: Could not connect to system
                                </div>
                                <button class="btn btn-primary mt-3" id="retryPickupProcess">Try Again</button>
                            `);

                            $('#retryPickupProcess').click(function() {
                                isProcessing = false;
                                $('#startPickUp').click();
                            });

                            isProcessing = false;
                        }
                    });
                });

                // Add cancel button
                $('#pickupRfidStatus').append('<button class="btn btn-sm btn-outline-secondary mt-2" id="cancelPickupCardScan">Cancel</button>');
                $('#cancelPickupCardScan').click(function() {
                    cancelPolling();
                    $('#pickupRfidStatus').html('');
                    $('#pickupStatusMessage').text('Card scanning cancelled. Click "Start Pick Up Process" to try again.');
                    isProcessing = false;
                });
            });

            // Refresh System Status (Admin)
            $('#refreshStatus').click(function() {
                $('#refreshStatus').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Refreshing...');

                $.ajax({
                    url: 'api_proxy.php?endpoint=status',
                    type: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        let statusHtml = '';

                        if (response && response.system_name) {
                            statusHtml += `<h4>System Status</h4>`;
                            statusHtml += `<div>Available Lockers: ${response.available_lockers.join(', ')}</div>`;
                            statusHtml += `<div>Active Cards: ${response.active_cards}</div>`;
                            statusHtml += `<div>Total Transactions: ${response.total_transactions}</div>`;
                            statusHtml += `<div>Last Updated: ${new Date().toLocaleTimeString()}</div>`;

                            $('#systemStatusIndicator').text('System Ready').removeClass('error').addClass('online');
                        } else {
                            statusHtml = '<div class="alert alert-warning">Unable to load system status.</div>';
                            $('#systemStatusIndicator').text('Connection Error').removeClass('online').addClass('error');
                        }

                        $('#admin-content .status-area').html(statusHtml);
                        $('#refreshStatus').prop('disabled', false).text('Refresh System Status');
                    },
                    error: function() {
                        $('#admin-content .status-area').html('<div class="alert alert-danger">Error connecting to system.</div>');
                        $('#systemStatusIndicator').text('Connection Error').removeClass('online').addClass('error');
                        $('#refreshStatus').prop('disabled', false).text('Refresh System Status');
                    }
                });
            });

            // Test RFID Reader (Admin)
            $('#testRfidReader').click(function() {
                $('#testRfidReader').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Testing...');
                $('#rfidTestStatus').html('<div class="alert alert-info">Testing RFID reader, please wait...</div>');

                // Simple test - try to read a card
                let testAttempts = 0;
                const maxTestAttempts = 10;
                let cardDetected = false;

                const testInterval = setInterval(function() {
                    testAttempts++;

                    // Update test status
                    $('#rfidTestStatus').html(`
                        <div class="alert alert-info">
                            Testing RFID reader (attempt ${testAttempts}/${maxTestAttempts})...
                            <div class="progress mt-2">
                                <div class="progress-bar" role="progressbar" style="width: ${(testAttempts/maxTestAttempts)*100}%"></div>
                            </div>
                        </div>
                        <div>Please place a card on the reader during the test.</div>
                    `);

                    // Try to read a card
                    $.ajax({
                        url: 'api_proxy.php?endpoint=read-card',
                        type: 'GET',
                        dataType: 'json',
                        success: function(response) {
                            if (response.success && response.card) {
                                // Card detected!
                                clearInterval(testInterval);
                                cardDetected = true;

                                $('#rfidTestStatus').html(`
                                    <div class="alert alert-success">
                                        RFID reader is working correctly!
                                    </div>
                                    <div>
                                        Card detected with ID: ${response.card.card_id}
                                    </div>
                                `);

                                $('#testRfidReader').prop('disabled', false).text('Test RFID Reader');

                                // Clear card queue
                                $.ajax({
                                    url: 'api_proxy.php?endpoint=clear-card-queue',
                                    type: 'POST'
                                });
                            } else if (testAttempts >= maxTestAttempts) {
                                // Test complete without detecting a card
                                clearInterval(testInterval);

                                if (!cardDetected) {
                                    $('#rfidTestStatus').html(`
                                        <div class="alert alert-warning">
                                            No card detected during test. This could indicate a problem with the RFID reader.
                                        </div>
                                        <div>
                                            Possible issues:
                                            <ul>
                                                <li>RFID reader not connected properly</li>
                                                <li>Power supply issues</li>
                                                <li>RFID cards not compatible</li>
                                                <li>Software configuration problems</li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-primary" id="retryRfidTest">Test Again</button>
                                    `);

                                    $('#retryRfidTest').click(function() {
                                        $('#testRfidReader').click();
                                    });
                                }

                                $('#testRfidReader').prop('disabled', false).text('Test RFID Reader');
                            }
                        },
                        error: function() {
                            if (testAttempts >= maxTestAttempts) {
                                clearInterval(testInterval);

                                $('#rfidTestStatus').html(`
                                    <div class="alert alert-danger">
                                        Error connecting to RFID reader. Please check system connections.
                                    </div>
                                    <button class="btn btn-primary" id="retryRfidTest">Test Again</button>
                                `);

                                $('#retryRfidTest').click(function() {
                                    $('#testRfidReader').click();
                                });

                                $('#testRfidReader').prop('disabled', false).text('Test RFID Reader');
                            }
                        }
                    });

                }, 2000); // Test every 2 seconds
            });


            // Device Management Tab Functionality
            function loadDeviceInfo() {
                $('#deviceInfoLoading').show();
                $('#deviceInfoContent').hide();

                $.ajax({
                    url: 'api_proxy.php?endpoint=device-info',
                    type: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if (response) {
                            $('#deviceName').val(response.device_name || '');
                            $('#deviceLocation').val(response.device_location || '');
                            $('#systemName').val(response.system_name || '');

                            $('#deviceInfoLoading').hide();
                            $('#deviceInfoContent').show();
                        } else {
                            $('#deviceInfoLoading').html(
                                '<div class="alert alert-warning">Unable to load device information.</div>'
                            );
                        }
                    },
                    error: function() {
                        $('#deviceInfoLoading').html(
                            '<div class="alert alert-danger">Error loading device information.</div>'
                        );
                    }
                });
            }

            // Handle device info form submission
            $('#deviceInfoForm').submit(function(e) {
                e.preventDefault();

                const deviceData = {
                    device_name: $('#deviceName').val(),
                    device_location: $('#deviceLocation').val(),
                    system_name: $('#systemName').val()
                };

                $('#deviceUpdateStatus').html(
                    '<div class="alert alert-info">Updating device information...</div>'
                );

                $.ajax({
                    url: 'api_proxy.php?endpoint=update-device-info',
                    type: 'POST',
                    dataType: 'json',
                    contentType: 'application/json',
                    data: JSON.stringify(deviceData),
                    success: function(response) {
                        if (response.success) {
                            $('#deviceUpdateStatus').html(
                                '<div class="alert alert-success">Device information updated successfully.</div>'
                            );
                        } else {
                            $('#deviceUpdateStatus').html(
                                `<div class="alert alert-danger">Error: ${response.message}</div>`
                            );
                        }
                    },
                    error: function() {
                        $('#deviceUpdateStatus').html(
                            '<div class="alert alert-danger">Error connecting to system.</div>'
                        );
                    }
                });
            });

            // Initialize device tab when clicked
            $('.tab[data-tab="device"]').click(function() {
                loadDeviceInfo();
            });
        });
    </script>
</body>

</html>