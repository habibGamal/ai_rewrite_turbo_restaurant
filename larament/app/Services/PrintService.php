<?php

namespace App\Services;

use App\Models\Order;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\Printer;

class PrintService
{
    /**
     * Print order receipt
     */
    public function printOrderReceipt(Order $order, array $images): void
    {
        $connector = new NetworkPrintConnector("192.168.1.4", 9100);
        $printer = new Printer($connector);

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
     * Print kitchen order
     */
    public function printKitchenOrder(Order $order, array $printData): void
    {
        $printer = Printer::findOrFail($printData['printer_id']);

        // Filter items to print
        $itemsToPrint = $printData['items'] ?? [];

        \Log::info("Printing kitchen order for order {$order->id} to printer {$printer->name}", [
            'items' => $itemsToPrint
        ]);

        // In a real implementation, you would:
        // - Format the kitchen ticket
        // - Send to specific kitchen printer
        // - Include only items for that printer/station
    }

    /**
     * Generate receipt data for frontend printing
     */
    public function generateReceiptData(Order $order): array
    {
        $order->load(['customer', 'driver', 'items.product', 'payments']);

        return [
            'order' => $order,
            'items' => $order->items->map(function ($item) {
                return [
                    'name' => $item->product->name,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'total' => $item->quantity * $item->price,
                    'notes' => $item->notes,
                ];
            }),
            'totals' => [
                'sub_total' => $order->sub_total,
                'tax' => $order->tax,
                'service' => $order->service,
                'discount' => $order->discount,
                'total' => $order->total,
            ],
            'payments' => $order->payments->map(function ($payment) {
                return [
                    'method' => $payment->method->label(),
                    'amount' => $payment->amount,
                ];
            }),
        ];
    }
}
