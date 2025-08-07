<?php

namespace App\Services;

use App\Models\Order;
use App\Enums\SettingKey;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\Printer;

class PrintService
{
    /**
     * Create appropriate print connector based on printer IP format
     */
    private function createConnector(string $printerIp): NetworkPrintConnector|WindowsPrintConnector
    {
        // Check if it's a UNC path (\\hostname\printername or \\ip\sharename)
        if (preg_match('/^\\\\\\\\[^\\\\]+\\\\[^\\\\]+/', $printerIp)) {
            return new WindowsPrintConnector($printerIp);
        }

        // Check if it's just a printer name (e.g., share_cash)
        if (!filter_var($printerIp, FILTER_VALIDATE_IP)) {
            return new WindowsPrintConnector($printerIp);
        }

        // Check if it's an IP address format (xxx.xxx.xxx.xxx)
        if (filter_var($printerIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return new NetworkPrintConnector($printerIp, 9100);
        }

        // Default to network connector if format is unclear
        return new NetworkPrintConnector($printerIp, 9100);
    }

    /**
     * Print order receipt
     */
    public function printOrderReceipt(Order $order, array $images): void
    {
        $printerIp = setting(SettingKey::CASHIER_PRINTER_IP);
        $connector = $this->createConnector($printerIp);
        $printer = new Printer($connector);

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        \Log::info("Printing receipt for order {$order->id}");

        try {
            // Combine all base64 images into one image
            $combinedImage = $this->combineBase64Images($images);

            // Create temporary file
            $tempFilePath = tempnam(sys_get_temp_dir(), 'receipt_') . '.png';

            // Save combined image to temporary file
            imagepng($combinedImage, $tempFilePath);
            // dd($tempFilePath);
            // Create EscposImage from temporary file
            $escposImage = EscposImage::load($tempFilePath);
            $printer->bitImage($escposImage);

            // Clean up resources
            // imagedestroy($combinedImage);
            // unlink($tempFilePath);

        } catch (\Exception $e) {
            \Log::error("Error printing receipt for order {$order->id}: " . $e->getMessage());
            throw $e;
        } finally {
            $printer->close();
        }
    }

    /**
     * Open the cashier drawer
     */
    public function openCashierDrawer(): void
    {
        try {
            $printerIp = setting(SettingKey::CASHIER_PRINTER_IP);
            $connector = $this->createConnector($printerIp);
            $printer = new Printer($connector);

            \Log::info("Opening cashier drawer");

            // Send pulse to open the drawer
            $printer->pulse();

        } catch (\Exception $e) {
            \Log::error("Error opening cashier drawer: " . $e->getMessage());
            throw $e;
        } finally {
            if (isset($printer)) {
                $printer->close();
            }
        }
    }

    /**
     * Combine multiple base64 images into one vertical image
     */
    private function combineBase64Images(array $base64Images): \GdImage
    {
        if (empty($base64Images)) {
            throw new \InvalidArgumentException('No images provided');
        }

        $gdImages = [];
        $totalHeight = 0;
        $maxWidth = 0;

        // Convert base64 images to GD resources and calculate dimensions
        foreach ($base64Images as $base64Image) {
            // Remove data URL prefix if present (data:image/png;base64,)
            $base64Data = preg_replace('/^data:image\/[a-zA-Z]+;base64,/', '', $base64Image);

            // Decode base64 and create GD image
            $imageData = base64_decode($base64Data);
            if ($imageData === false) {
                throw new \InvalidArgumentException('Invalid base64 image data');
            }

            $gdImage = imagecreatefromstring($imageData);
            if ($gdImage === false) {
                throw new \InvalidArgumentException('Could not create image from string');
            }

            $gdImages[] = $gdImage;
            $width = imagesx($gdImage);
            $height = imagesy($gdImage);

            $totalHeight += $height;
            $maxWidth = max($maxWidth, $width);
        }

        // Create combined image canvas
        $combinedImage = imagecreatetruecolor($maxWidth, $totalHeight);
        if ($combinedImage === false) {
            // Clean up created images
            foreach ($gdImages as $gdImage) {
                imagedestroy($gdImage);
            }
            throw new \RuntimeException('Could not create combined image canvas');
        }

        // Set white background
        $white = imagecolorallocate($combinedImage, 255, 255, 255);
        imagefill($combinedImage, 0, 0, $white);

        // Copy each image to the combined canvas
        $currentY = 0;
        foreach ($gdImages as $gdImage) {
            $width = imagesx($gdImage);
            $height = imagesy($gdImage);

            // Center the image horizontally if it's smaller than maxWidth
            $x = ($maxWidth - $width) / 2;

            // Copy image to combined canvas
            imagecopy($combinedImage, $gdImage, $x, $currentY, 0, 0, $width, $height);

            $currentY += $height;

            // Clean up individual image
            imagedestroy($gdImage);
        }

        return $combinedImage;
    }

    /**
     * Print kitchen image to specific printer
     */
    public function printKitchenImage(string $printerId, string $base64Image): void
    {
        try {
            $printer = \App\Models\Printer::findOrFail($printerId);

            if (!$printer->ip_address) {
                \Log::warning("Printer {$printer->name} has no IP address configured");
                return;
            }

            $connector = $this->createConnector($printer->ip_address);
            $escposPrinter = new Printer($connector);
            $escposPrinter->setJustification(Printer::JUSTIFY_CENTER);

            \Log::info("Printing kitchen order to printer {$printer->name} ({$printer->ip_address})");

            // Remove data URL prefix if present (data:image/png;base64,)
            $base64Data = preg_replace('/^data:image\/[a-zA-Z]+;base64,/', '', $base64Image);

            // Decode base64 and create GD image
            $imageData = base64_decode($base64Data);
            if ($imageData === false) {
                throw new \InvalidArgumentException('Invalid base64 image data');
            }

            // Create temporary file
            $tempFilePath = tempnam(sys_get_temp_dir(), 'kitchen_') . '.png';
            file_put_contents($tempFilePath, $imageData);

            // Create EscposImage from temporary file
            $escposImage = EscposImage::load($tempFilePath);
            $escposPrinter->bitImage($escposImage);

            // Clean up
            unlink($tempFilePath);

        } catch (\Exception $e) {
            \Log::error("Error printing kitchen image to printer {$printerId}: " . $e->getMessage());
            throw $e;
        } finally {
            if (isset($escposPrinter)) {
                $escposPrinter->close();
            }
        }
    }

    /**
     * Test cashier printer connection with sample text
     */
    public function testCashierPrinter(): void
    {
        try {
            $printerIp = setting(SettingKey::CASHIER_PRINTER_IP);
            $connector = $this->createConnector($printerIp);
            $printer = new Printer($connector);

            \Log::info("Testing cashier printer connection");

            // Print test text
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
            $printer->text("Printer Test\n");
            $printer->selectPrintMode();
            $printer->text("------------------------\n");
            $printer->text("Date: " . now()->format('Y-m-d H:i:s') . "\n");
            $printer->text("IP: {$printerIp}\n");
            $printer->text("------------------------\n");
            $printer->text("If you see this text, the printer is working correctly\n");
            $printer->feed(3);
            $printer->cut();

            \Log::info("Test print sent successfully to cashier printer");

        } catch (\Exception $e) {
            \Log::error("Error testing cashier printer: " . $e->getMessage());
            throw $e;
        } finally {
            if (isset($printer)) {
                $printer->close();
            }
        }
    }

}
