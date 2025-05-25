<?php
/**
 * exPricer Success Page
 * 
 * This page handles successful payments and provides download links
 * for digital products.
 */

// Success page for exPricer
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Load configuration
require_once __DIR__ . '/src/autoload.php';
use exPricer\Core\Config;
use exPricer\Core\SalesTracker;
use exPricer\Core\SmtpMailer;

// Initialize variables
$error = null;
$download_url = null;
$email_sent = false;

try {
    Config::load();
    $work_type = Config::get('WORK_TYPE');
} catch (Exception $e) {
    error_log("Configuration error: " . $e->getMessage());
    die("Configuration error. Please contact support.");
}

// File configuration
$PRODUCT_FILE = [
    'name' => Config::get('PRODUCT_FILE_NAME'),
    'size' => Config::get('PRODUCT_FILE_SIZE')
];

// Simple JWT implementation
function generateDownloadToken($fileId, $fileName) {
    $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload = base64_encode(json_encode([
        'file_id' => $fileId,
        'file_name' => $fileName,
        'exp' => time() + (Config::get('DOWNLOAD_EXPIRY_HOURS', 24) * 60 * 60)
    ]));
    
    $signature = hash_hmac('sha256', "$header.$payload", Config::get('DOWNLOAD_TOKEN_SECRET'), true);
    $signature = base64_encode($signature);
    
    return "$header.$payload.$signature";
}

// Simple Stripe API call
function getStripeSession($sessionId) {
    try {
        // Validate session ID format
        if (!preg_match('/^cs_(test|live)_[a-zA-Z0-9]+$/', $sessionId)) {
            return null;
        }
        
        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . $sessionId);
        if ($ch === false) {
            return null;
        }
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, Config::get('STRIPE_SECRET_KEY') . ':');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        if ($response === false) {
            return null;
        }
        
        $data = json_decode($response, true);
        if (!is_array($data)) {
            return null;
        }
        
        return $data;
    } catch (\Exception $e) {
        return null;
    }
}

$session_id = $_GET['session_id'] ?? null;

if ($session_id) {
    // Retrieve the checkout session
    $session = getStripeSession($session_id);
    
    if ($session && isset($session['payment_status']) && $session['payment_status'] === 'paid') {
        // Generate a secure download token first
        try {
            if (!isset($PRODUCT_FILE['name'])) {
                throw new \RuntimeException('Product file information is missing');
            }
            
            // Only generate download token for digital items
            if ($work_type === 'digital') {
                $token = generateDownloadToken($PRODUCT_FILE['name'], $PRODUCT_FILE['name']);
                if (!$token) {
                    throw new \RuntimeException('Failed to generate download token');
                }
                
                // Create the download URL
                $baseUrl = rtrim(str_replace('/public', '', Config::get('APP_URL')), '/');
                $download_url = $baseUrl . '/download?token=' . urlencode($token);
                error_log("Generated download URL: " . $download_url);
            }
            
        } catch (\Exception $e) {
            error_log('Failed to generate download token: ' . $e->getMessage());
            if ($work_type === 'digital') {
                $error = 'Failed to generate download link';
            }
        }
        
        // Record the sale
        try {
            $salesTracker = new SalesTracker();
            $metadata = $session['metadata'] ?? [];
            $selectedLevel = json_decode($metadata['selected_level'] ?? '{}', true);
            
            // Get customer email from customer_details
            $customerEmail = $session['customer_details']['email'] ?? '';
            $amountTotal = $session['amount_total'] ?? 0;
            $sessionId = $session['id'] ?? null;
            
            if ($customerEmail && $amountTotal > 0 && $sessionId) {
                // Calculate copies eliminated based on the selected level
                $copiesEliminated = 1; // Default to 1 if not specified
                if (isset($selectedLevel['remaining_copies'])) {
                    $currentCopiesSold = $salesTracker->getCopiesSold();
                    $maxCopies = (int)Config::get('MAX_COPIES');
                    $currentRemaining = $maxCopies - $currentCopiesSold;
                    $copiesEliminated = $currentRemaining - $selectedLevel['remaining_copies'];
                }
                
                try {
                    $salesTracker->recordSale(
                        $copiesEliminated,
                        $customerEmail,
                        $amountTotal / 100, // Convert from cents
                        $sessionId
                    );
                    error_log("Sale recorded successfully for session $sessionId");
                    
                    // Now that the sale is recorded, send the email
                    if (isset($session['customer_details']['email'])) {
                        // Verify email configuration
                        $requiredEmailConfigs = [
                            'MAIL_FROM' => Config::get('MAIL_FROM'),
                            'MAIL_REPLY_TO' => Config::get('MAIL_REPLY_TO'),
                            'MAIL_SMTP_HOST' => Config::get('MAIL_SMTP_HOST'),
                            'MAIL_SMTP_PORT' => Config::get('MAIL_SMTP_PORT'),
                            'MAIL_SMTP_USERNAME' => Config::get('MAIL_SMTP_USERNAME'),
                            'MAIL_SMTP_PASSWORD' => Config::get('MAIL_SMTP_PASSWORD')
                        ];
                        
                        $missingConfigs = array_filter($requiredEmailConfigs, function($value) {
                            return empty($value);
                        });
                        
                        if (empty($missingConfigs)) {
                            // Create SMTP mailer instance
                            $mailer = new SmtpMailer(
                                Config::get('MAIL_SMTP_HOST'),
                                Config::get('MAIL_SMTP_PORT'),
                                Config::get('MAIL_SMTP_USERNAME'),
                                Config::get('MAIL_SMTP_PASSWORD'),
                                Config::get('MAIL_FROM'),
                                Config::get('MAIL_REPLY_TO')
                            );
                            
                            // Set a short timeout and enable debug logging
                            $mailer->setTimeout(5); // 5 seconds timeout
                            $mailer->setDebug(true);
                            
                            // Email content based on work type
                            if ($work_type === 'digital') {
                                $subject = 'Your Download Link - ' . Config::get('PRODUCT_NAME');
                            } else {
                                $subject = 'Your Purchase Confirmation - ' . Config::get('PRODUCT_NAME');
                            }
                            
                            if ($work_type === 'digital') {
                                if (!$download_url) {
                                    $message = "
                                        <html>
                                        <head>
                                            <title>Confirmation of Your Purchase</title>
                                        </head>
                                        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                                            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                                                <h2 style='color: #2c3e50;'>Thank you for your purchase!</h2>
                                                <p>Your order for " . htmlspecialchars(Config::get('PRODUCT_NAME')) . " has been received.</p>
                                                <p>We're currently preparing your download. You'll receive another email with your download link shortly.</p>
                                                <p>If you have any questions, please reply to this email.</p>
                                            </div>
                                        </body>
                                        </html>
                                    ";
                                } else {
                                    $escaped_url = htmlspecialchars($download_url);
                                    $message = "
                                        <html>
                                        <head>
                                            <title>Your Download Link</title>
                                        </head>
                                        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                                            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                                                <h2 style='color: #2c3e50;'>Thank you for your purchase!</h2>
                                                <p>Your download link is ready:</p>
                                                <div style='margin: 20px 0; text-align: center;'>
                                                    <a href='{$escaped_url}' 
                                                       style='background-color: #3498db; 
                                                              color: white; 
                                                              padding: 12px 24px; 
                                                              text-decoration: none; 
                                                              border-radius: 5px; 
                                                              display: inline-block;'>
                                                        Download Now
                                                    </a>
                                                </div>
                                                <p style='font-size: 12px; color: #666; margin-top: 10px;'>
                                                    If the button doesn't work, you can copy and paste this link into your browser:<br>
                                                    <span style='word-break: break-all;'>{$escaped_url}</span>
                                                </p>
                                                <p>This link will expire in " . Config::get('DOWNLOAD_EXPIRY_HOURS') . " hours.</p>
                                                <p>If you have any questions, please reply to this email.</p>
                                            </div>
                                        </body>
                                        </html>
                                    ";
                                }
                            } else if (Config::get('WORK_TYPE') === 'physical') {
                                $message = "
                                    <html>
                                    <head>
                                        <title>Your Purchase Confirmation</title>
                                    </head>
                                    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                                        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                                            <h2 style='color: #2c3e50;'>Thank you for your purchase!</h2>
                                            <p>Your order for " . htmlspecialchars(Config::get('PRODUCT_NAME')) . " has been received.</p>
                                            
                                            <div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                                                <h3 style='color: #2c3e50; margin-top: 0;'>Next Steps</h3>
                                                <p>To complete your order, please reply to this email with your full shipping address including:</p>
                                                <ul style='list-style-type: none; padding: 0; margin: 15px 0;'>
                                                    <li style='margin: 8px 0; padding: 8px; background: #fff; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);'>Full Name</li>
                                                    <li style='margin: 8px 0; padding: 8px; background: #fff; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);'>Street Address</li>
                                                    <li style='margin: 8px 0; padding: 8px; background: #fff; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);'>City</li>
                                                    <li style='margin: 8px 0; padding: 8px; background: #fff; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);'>State/Province</li>
                                                    <li style='margin: 8px 0; padding: 8px; background: #fff; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);'>Postal Code</li>
                                                    <li style='margin: 8px 0; padding: 8px; background: #fff; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);'>Country</li>
                                                </ul>
                                            </div>
                                            
                                            <p>Once we receive your shipping information, we will process your order and send you a shipping confirmation.</p>
                                            <p>If you have any questions, please reply to this email.</p>
                                        </div>
                                    </body>
                                    </html>
                                ";
                            } else { // Just in case...
                                $message = "
                                    <html>
                                    <head>
                                        <title>Your Purchase Confirmation</title>
                                    </head>
                                    <body>
                                        <h2>Thank you for your purchase!</h2>
                                        <p>Your order for " . htmlspecialchars(Config::get('PRODUCT_NAME')) . " has been received.</p>
                                        <p>To complete your order, please reply to this email.</p>
                                    </body>
                                    </html>
                                ";                                                                
                            }
                            
                            try {
                                $result = $mailer->send(
                                    $session['customer_details']['email'],
                                    $subject,
                                    $message
                                );
                                $email_sent = true;
                            } catch (\Exception $e) {
                                $email_sent = false;
                            }
                        } else {
                            $error = "Email configuration is incomplete. Please contact support.";
                            $email_sent = false;
                        }
                    } else {
                        $email_sent = false;
                    }
                } catch (\Exception $e) {
                    $email_sent = false;
                }
            }
        } catch (\Exception $e) {
            error_log("Error initializing sales tracker: " . $e->getMessage());
        }
    } else {
        $error = 'Payment not completed';
        $email_sent = false;
    }
} else {
    $error = 'No session ID provided';
    $email_sent = false;
}

// Debug output
error_log("Final email_sent value before HTML: " . ($email_sent ? 'true' : 'false'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Successful - <?php echo htmlspecialchars(Config::get('PRODUCT_NAME')); ?></title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-bottom: 30px;
        }
        .success-message {
            color: #28a745;
            margin-bottom: 20px;
            padding: 15px;
            background: #d4edda;
            border-radius: 5px;
        }
        .download-section {
            margin: 30px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .download-button {
            display: inline-block;
            background: #007bff;
            color: white;
            text-decoration: none;
            padding: 15px 30px;
            border-radius: 5px;
            font-size: 18px;
            transition: background 0.3s ease;
        }
        .download-button:hover {
            background: #0056b3;
        }
        .email-notice {
            margin-top: 20px;
            padding: 15px;
            background: #fff3cd;
            border-radius: 5px;
            color: #856404;
        }
        .expiry-notice {
            margin-top: 10px;
            color: #666;
            font-size: 14px;
        }
        .shipping-info {
            margin: 30px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .shipping-list {
            list-style-type: none;
            padding: 0;
            margin: 20px 0;
        }
        .shipping-list li {
            margin: 10px 0;
            padding: 10px;
            background: #fff;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Purchase Successful!</h1>
        
        <?php if ($error): ?>
            <div class="error">
                <p><?php echo htmlspecialchars($error); ?></p>
            </div>
        <?php else: ?>
            <div class="success-message">
                <p>Thank you for your purchase of <?php echo htmlspecialchars(Config::get('PRODUCT_NAME')); ?>!</p>
            </div>

            <?php if (Config::get('WORK_TYPE') === 'digital' && $download_url): ?>
                <div class="download-section">
                    <h2>Your Download</h2>
                    <p>Click the button below to download your file:</p>
                    <a href="<?php echo htmlspecialchars($download_url); ?>" class="download-button">Download Now</a>
                    <p class="expiry-notice">This download link will expire in <?php echo Config::get('DOWNLOAD_EXPIRY_HOURS'); ?> hours.</p>
                </div>
            <?php else: ?>
                <div class="shipping-info">
                    <h2>Next Steps</h2>
                    <p>To complete your order, please reply to the confirmation email with your full shipping address including:</p>
                    <ul class="shipping-list">
                        <li>Full Name</li>
                        <li>Street Address</li>
                        <li>City</li>
                        <li>State/Province</li>
                        <li>Postal Code</li>
                        <li>Country</li>
                    </ul>
                    <p>Once we receive your shipping information, we will process your order and send you a shipping confirmation.</p>
                </div>
            <?php endif; ?>

            <?php if ($email_sent): ?>
                <div class="email-notice">
                    <p>A confirmation email has been sent to <?php echo htmlspecialchars($session['customer_details']['email']); ?>.</p>
                </div>
            <?php else: ?>
                <div class="email-notice">
                    <p>We attempted to send a confirmation email, but there was an issue. Please contact support if you don't receive it within a few minutes.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html> 