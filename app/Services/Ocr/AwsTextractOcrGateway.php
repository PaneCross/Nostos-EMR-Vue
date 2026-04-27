<?php

namespace App\Services\Ocr;

/**
 * AWS Textract OCR gateway stub : Phase M4.
 * Activated when AWS credentials + SDK land. Throws until configured.
 */
class AwsTextractOcrGateway implements OcrGateway
{
    public function name(): string { return 'aws_textract'; }

    public function extractText(string $filePath): string
    {
        throw new \RuntimeException('AwsTextractOcrGateway: awaiting AWS SDK + IAM credentials.');
    }
}
