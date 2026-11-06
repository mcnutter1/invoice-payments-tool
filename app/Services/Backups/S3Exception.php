<?php

namespace App\Services\Backups;

use RuntimeException;

/** An S3 error response, with a plain-language explanation for administrators. */
class S3Exception extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly ?string $awsCode, public readonly ?string $awsMessage, public readonly ?string $region = null)
    {
        parent::__construct(self::explain($status, $awsCode, $awsMessage, $region));
    }

    public static function fromResponse(int $status, string $body, ?string $regionHeader = null): self
    {
        $xml = @simplexml_load_string($body);
        $code = $xml ? (string) ($xml->Code ?? '') : '';
        $msg = $xml ? (string) ($xml->Message ?? '') : '';
        $region = $xml ? ((string) ($xml->Region ?? '') ?: $regionHeader) : $regionHeader;

        return new self($status, $code ?: null, $msg ?: null, $region ?: null);
    }

    public static function explain(int $status, ?string $code, ?string $message, ?string $region): string
    {
        return match ($code) {
            'AccessDenied' => 'Access denied: the access key isn’t allowed to do this on the bucket/prefix. Attach the IAM policy shown in the setup guide.',
            'NoSuchBucket' => 'The bucket doesn’t exist (check the name and the AWS account).',
            'InvalidAccessKeyId' => 'The access key ID isn’t recognized by AWS.',
            'SignatureDoesNotMatch' => 'The secret access key is wrong for this access key ID.',
            'AuthorizationHeaderMalformed', 'PermanentRedirect', 'IllegalLocationConstraintException'
                => 'The bucket is in a different region'.($region ? ' ('.$region.')' : '').'. Set the region to match the bucket.',
            'InvalidStorageClass' => 'This storage class isn’t supported by the bucket/endpoint. Choose another storage class.',
            'RequestTimeTooSkewed' => 'The server clock is too far off for AWS to accept the request. Fix the server time (NTP).',
            'KMS.AccessDeniedException', 'KMSAccessDenied' => 'The bucket uses a KMS key this access key can’t use. Grant kms:GenerateDataKey and kms:Decrypt, or use SSE-S3.',
            default => 'S3 returned HTTP '.$status.($code ? ' '.$code : '').($message ? ': '.$message : '').'.',
        };
    }
}
