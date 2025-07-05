# Security Guide

This guide outlines security best practices for using the Laravel OCI Driver.

---

## Security Overview

The Laravel OCI Driver handles sensitive data and credentials that require careful security considerations. This guide covers key areas of security including authentication, authorization, data protection, and monitoring.

### Security Principles

1. **Least Privilege**: Grant minimum necessary permissions
2. **Defense in Depth**: Multiple layers of security
3. **Zero Trust**: Verify everything, trust nothing
4. **Data Protection**: Encrypt data at rest and in transit
5. **Audit Everything**: Log and monitor all activities

---

## Key Management Security

### Private Key Protection

```bash
# Secure key file permissions
chmod 700 .oci/                    # Directory: owner read/write/execute only
chmod 600 .oci/api_key.pem        # Private key: owner read/write only
chmod 644 .oci/api_key_public.pem # Public key: owner read/write, others read

# Verify permissions
ls -la .oci/
# Should show: drwx------ for directory, -rw------- for private key
```

### Key Storage Best Practices

```bash
# Production: Store keys outside web root
sudo mkdir -p /secure/oci-keys
sudo chmod 700 /secure/oci-keys
sudo chown www-data:www-data /secure/oci-keys

# Move keys to secure location
sudo mv .oci/api_key.pem /secure/oci-keys/
sudo chmod 600 /secure/oci-keys/api_key.pem
sudo chown www-data:www-data /secure/oci-keys/api_key.pem

# Update configuration
# OCI_PRIVATE_KEY_PATH=/secure/oci-keys/api_key.pem
```

### Environment Variable Security

```env
# ❌ BAD: Storing private key content directly
OCI_PRIVATE_KEY_CONTENT="-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7VJTUt9Us8cKB
-----END PRIVATE KEY-----"

# ✅ GOOD: Using secure file path
OCI_PRIVATE_KEY_PATH=/secure/oci-keys/api_key.pem

# ✅ BETTER: Using encrypted environment variables (Laravel Secrets)
OCI_PRIVATE_KEY_PATH="${ENCRYPTED_KEY_PATH}"
```

### Key Rotation Strategy

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use LaravelOCI\LaravelOciDriver\KeyProvider\FileKeyProvider;

class OciKeyRotationService
{
    public function rotateKeys($oldKeyPath, $newKeyPath)
    {
        // 1. Generate new key pair
        $this->generateNewKeyPair($newKeyPath);
        
        // 2. Upload new public key to OCI Console
        $fingerprint = $this->uploadPublicKeyToOci($newKeyPath . '.pub');
        
        // 3. Update configuration with new key
        $this->updateConfiguration($newKeyPath, $fingerprint);
        
        // 4. Test new key
        $this->testNewKey();
        
        // 5. Deactivate old key in OCI Console
        $this->deactivateOldKey();
        
        // 6. Securely delete old key
        $this->secureDeleteOldKey($oldKeyPath);
        
        return true;
    }
    
    private function generateNewKeyPair($keyPath)
    {
        // Generate new 4096-bit RSA key for enhanced security
        $command = "openssl genrsa -out {$keyPath} 4096";
        exec($command);
        
        // Generate public key
        $command = "openssl rsa -in {$keyPath} -pubout -out {$keyPath}.pub";
        exec($command);
        
        // Set secure permissions
        chmod($keyPath, 0600);
        chmod($keyPath . '.pub', 0644);
    }
    
    private function secureDeleteOldKey($keyPath)
    {
        // Overwrite file with random data before deletion
        $command = "shred -vfz -n 3 {$keyPath}";
        exec($command);
    }
}
```

---

## Access Control

### IAM Policies for OCI Users

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "objectstorage:GetObject",
        "objectstorage:PutObject",
        "objectstorage:DeleteObject",
        "objectstorage:ListObjects"
      ],
      "Resource": [
        "arn:oci:objectstorage:*:*:bucket/your-app-bucket/*"
      ],
      "Condition": {
        "StringEquals": {
          "objectstorage:bucket-name": "your-app-bucket"
        }
      }
    },
    {
      "Effect": "Deny",
      "Action": "*",
      "Resource": "*",
      "Condition": {
        "Bool": {
          "oci:ViaConsole": "true"
        }
      }
    }
  ]
}
```

### Application-Level Access Control

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ValidateFileAccess
{
    public function handle(Request $request, Closure $next)
    {
        $filePath = $request->route('path');
        $user = Auth::user();
        
        // Check if user has permission to access this file
        if (!$this->userCanAccessFile($user, $filePath)) {
            abort(403, 'Access denied');
        }
        
        // Log access attempt
        $this->logFileAccess($user, $filePath, 'accessed');
        
        return $next($request);
    }
    
    private function userCanAccessFile($user, $filePath)
    {
        // Example access control logic
        
        // Admin users can access everything
        if ($user->hasRole('admin')) {
            return true;
        }
        
        // Users can only access their own files
        if (str_starts_with($filePath, "users/{$user->id}/")) {
            return true;
        }
        
        // Check for shared access
        if ($this->hasSharedAccess($user, $filePath)) {
            return true;
        }
        
        return false;
    }
    
    private function hasSharedAccess($user, $filePath)
    {
        // Check database for shared file permissions
        return \App\Models\FileShare::where('file_path', $filePath)
            ->where('user_id', $user->id)
            ->where('expires_at', '>', now())
            ->exists();
    }
    
    private function logFileAccess($user, $filePath, $action)
    {
        \Log::info('File access', [
            'user_id' => $user->id,
            'file_path' => $filePath,
            'action' => $action,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now()->toISOString(),
        ]);
    }
}
```

### File Upload Security

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SecureFileUploadController extends Controller
{
    private array $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/pdf',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    
    private int $maxFileSize = 10 * 1024 * 1024; // 10MB
    
    public function upload(Request $request)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:' . ($this->maxFileSize / 1024), // Laravel expects KB
                function ($attribute, $value, $fail) {
                    if (!in_array($value->getMimeType(), $this->allowedMimeTypes)) {
                        $fail('File type not allowed.');
                    }
                    
                    // Additional security checks
                    if (!$this->isSecureFile($value)) {
                        $fail('File failed security validation.');
                    }
                },
            ],
        ]);
        
        $file = $request->file('file');
        
        // Generate secure filename
        $filename = $this->generateSecureFilename($file);
        
        // Scan file for malware (if available)
        if (!$this->scanForMalware($file)) {
            return response()->json(['error' => 'File failed security scan'], 400);
        }
        
        // Upload with security metadata
        $path = Storage::disk('oci')->putFileAs(
            'uploads/' . auth()->id(),
            $file,
            $filename,
            [
                'metadata' => [
                    'uploaded_by' => auth()->id(),
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_hash' => hash_file('sha256', $file->getRealPath()),
                    'upload_ip' => $request->ip(),
                    'upload_time' => now()->toISOString(),
                ],
                'visibility' => 'private',
            ]
        );
        
        // Log successful upload
        \Log::info('Secure file upload', [
            'user_id' => auth()->id(),
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);
        
        return response()->json([
            'success' => true,
            'path' => $path,
            'filename' => $filename,
        ]);
    }
    
    private function generateSecureFilename($file)
    {
        $extension = $file->getClientOriginalExtension();
        $hash = hash('sha256', $file->getClientOriginalName() . time());
        
        return Str::substr($hash, 0, 32) . '.' . $extension;
    }
    
    private function isSecureFile($file)
    {
        // Check file signature/magic bytes
        $handle = fopen($file->getRealPath(), 'rb');
        $header = fread($handle, 16);
        fclose($handle);
        
        // Validate file signature matches declared MIME type
        return $this->validateFileSignature($header, $file->getMimeType());
    }
    
    private function validateFileSignature($header, $mimeType)
    {
        $signatures = [
            'image/jpeg' => ["\xFF\xD8\xFF"],
            'image/png' => ["\x89\x50\x4E\x47"],
            'image/gif' => ["\x47\x49\x46\x38"],
            'application/pdf' => ["\x25\x50\x44\x46"],
        ];
        
        if (!isset($signatures[$mimeType])) {
            return true; // Allow if no signature check available
        }
        
        foreach ($signatures[$mimeType] as $signature) {
            if (str_starts_with($header, $signature)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function scanForMalware($file)
    {
        // Integrate with ClamAV or similar antivirus
        // This is a placeholder implementation
        
        try {
            $command = "clamscan --no-summary " . escapeshellarg($file->getRealPath());
            $output = [];
            $returnCode = 0;
            
            exec($command, $output, $returnCode);
            
            return $returnCode === 0; // 0 = clean, 1 = infected
        } catch (\Exception $e) {
            \Log::warning('Malware scan failed', ['error' => $e->getMessage()]);
            return true; // Allow if scan fails (adjust based on your security policy)
        }
    }
}
```

---

## Data Encryption

### Encryption at Rest

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;

class EncryptedFileService
{
    public function uploadEncrypted($file, $path)
    {
        // Read file content
        $content = file_get_contents($file->getRealPath());
        
        // Encrypt content
        $encryptedContent = Crypt::encrypt($content);
        
        // Upload encrypted content
        $result = Storage::disk('oci')->put($path . '.encrypted', $encryptedContent, [
            'metadata' => [
                'encrypted' => 'true',
                'algorithm' => 'AES-256-CBC',
                'original_name' => $file->getClientOriginalName(),
                'original_size' => $file->getSize(),
            ]
        ]);
        
        return $result;
    }
    
    public function downloadDecrypted($path)
    {
        // Download encrypted content
        $encryptedContent = Storage::disk('oci')->get($path . '.encrypted');
        
        // Decrypt content
        $content = Crypt::decrypt($encryptedContent);
        
        return $content;
    }
    
    public function uploadWithCustomKey($file, $path, $encryptionKey)
    {
        $content = file_get_contents($file->getRealPath());
        
        // Use custom encryption key
        $cipher = 'AES-256-CBC';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($cipher));
        $encrypted = openssl_encrypt($content, $cipher, $encryptionKey, 0, $iv);
        
        // Store IV with encrypted data
        $encryptedData = base64_encode($iv . $encrypted);
        
        return Storage::disk('oci')->put($path, $encryptedData, [
            'metadata' => [
                'encrypted' => 'true',
                'cipher' => $cipher,
                'custom_key' => 'true',
            ]
        ]);
    }
}
```

### Client-Side Encryption

```javascript
// Frontend encryption before upload
class ClientSideEncryption {
    async encryptFile(file, password) {
        const key = await this.deriveKey(password);
        const iv = crypto.getRandomValues(new Uint8Array(12));
        
        const fileBuffer = await file.arrayBuffer();
        
        const encrypted = await crypto.subtle.encrypt(
            { name: 'AES-GCM', iv: iv },
            key,
            fileBuffer
        );
        
        // Combine IV and encrypted data
        const combined = new Uint8Array(iv.length + encrypted.byteLength);
        combined.set(iv);
        combined.set(new Uint8Array(encrypted), iv.length);
        
        return new Blob([combined], { type: 'application/octet-stream' });
    }
    
    async deriveKey(password) {
        const encoder = new TextEncoder();
        const keyMaterial = await crypto.subtle.importKey(
            'raw',
            encoder.encode(password),
            { name: 'PBKDF2' },
            false,
            ['deriveKey']
        );
        
        return crypto.subtle.deriveKey(
            {
                name: 'PBKDF2',
                salt: encoder.encode('salt'), // Use proper salt in production
                iterations: 100000,
                hash: 'SHA-256'
            },
            keyMaterial,
            { name: 'AES-GCM', length: 256 },
            false,
            ['encrypt', 'decrypt']
        );
    }
}
```

---

## Network Security

### HTTPS/TLS Configuration

```php
// config/filesystems.php
'oci' => [
    'driver' => 'oci',
    // ... other config
    'options' => [
        'verify_ssl' => true,           // Always verify SSL certificates
        'ssl_cert' => '/path/to/cert',  // Custom SSL certificate if needed
        'ssl_key' => '/path/to/key',    // Custom SSL key if needed
        'ssl_ca' => '/path/to/ca',      // Custom CA bundle if needed
        'timeout' => 30,
        'connect_timeout' => 10,
    ],
],
```

### IP Whitelisting

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RestrictOciAccess
{
    private array $allowedIps = [
        '192.168.1.0/24',    // Internal network
        '10.0.0.0/8',        // Private network
        '203.0.113.0/24',    // Office network
    ];
    
    public function handle(Request $request, Closure $next)
    {
        $clientIp = $request->ip();
        
        if (!$this->isIpAllowed($clientIp)) {
            \Log::warning('Unauthorized OCI access attempt', [
                'ip' => $clientIp,
                'user_agent' => $request->userAgent(),
                'path' => $request->path(),
            ]);
            
            abort(403, 'Access denied from this IP address');
        }
        
        return $next($request);
    }
    
    private function isIpAllowed($ip)
    {
        foreach ($this->allowedIps as $allowedRange) {
            if ($this->ipInRange($ip, $allowedRange)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function ipInRange($ip, $range)
    {
        if (strpos($range, '/') !== false) {
            list($subnet, $mask) = explode('/', $range);
            return (ip2long($ip) & ~((1 << (32 - $mask)) - 1)) === ip2long($subnet);
        }
        
        return $ip === $range;
    }
}
```

### Rate Limiting

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class OciRateLimit
{
    public function handle(Request $request, Closure $next)
    {
        $key = 'oci-operations:' . $request->ip();
        
        if (RateLimiter::tooManyAttempts($key, 100)) { // 100 requests per minute
            \Log::warning('OCI rate limit exceeded', [
                'ip' => $request->ip(),
                'user_id' => auth()->id(),
            ]);
            
            abort(429, 'Too many requests');
        }
        
        RateLimiter::hit($key, 60); // 60 seconds window
        
        return $next($request);
    }
}
```

---

## Compliance Considerations

### GDPR Compliance

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class GdprComplianceService
{
    public function deleteUserData($userId)
    {
        // Find all files belonging to the user
        $userFiles = Storage::disk('oci')->files("users/{$userId}");
        
        foreach ($userFiles as $file) {
            // Log deletion for audit trail
            \Log::info('GDPR data deletion', [
                'user_id' => $userId,
                'file_path' => $file,
                'deleted_at' => now()->toISOString(),
                'reason' => 'GDPR right to be forgotten',
            ]);
            
            // Secure deletion
            Storage::disk('oci')->delete($file);
        }
        
        // Also delete any shared files
        $sharedFiles = \App\Models\FileShare::where('user_id', $userId)->get();
        foreach ($sharedFiles as $share) {
            $share->delete();
        }
        
        return count($userFiles);
    }
    
    public function exportUserData($userId)
    {
        $userData = [
            'user_id' => $userId,
            'export_date' => now()->toISOString(),
            'files' => [],
        ];
        
        $userFiles = Storage::disk('oci')->files("users/{$userId}");
        
        foreach ($userFiles as $file) {
            $userData['files'][] = [
                'path' => $file,
                'size' => Storage::disk('oci')->size($file),
                'last_modified' => Storage::disk('oci')->lastModified($file),
                'download_url' => $this->generateSecureDownloadUrl($file),
            ];
        }
        
        return $userData;
    }
    
    private function generateSecureDownloadUrl($file)
    {
        // Generate time-limited signed URL
        return url("/secure-download/{$file}?token=" . encrypt([
            'file' => $file,
            'expires' => now()->addHours(24)->timestamp,
        ]));
    }
}
```

### SOC 2 Compliance

```php
<?php

namespace App\Services;

class Soc2AuditService
{
    public function logDataAccess($event, $context = [])
    {
        $auditLog = [
            'event_type' => $event,
            'timestamp' => now()->toISOString(),
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'session_id' => session()->getId(),
            'context' => $context,
        ];
        
        // Log to secure audit log
        \Log::channel('audit')->info('SOC2 Audit Event', $auditLog);
        
        // Also send to external audit system
        $this->sendToAuditSystem($auditLog);
    }
    
    public function generateAuditReport($startDate, $endDate)
    {
        // Generate compliance report
        return [
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'total_operations' => $this->countOperations($startDate, $endDate),
            'security_incidents' => $this->countSecurityIncidents($startDate, $endDate),
            'access_violations' => $this->countAccessViolations($startDate, $endDate),
            'data_exports' => $this->countDataExports($startDate, $endDate),
        ];
    }
    
    private function sendToAuditSystem($auditLog)
    {
        // Send to external audit/SIEM system
        // Implementation depends on your audit system
    }
}
```

---

## Security Monitoring

### Intrusion Detection

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class SecurityMonitoringService
{
    public function detectSuspiciousActivity($userId, $activity)
    {
        $alerts = [];
        
        // Check for unusual upload patterns
        if ($this->detectUnusualUploads($userId)) {
            $alerts[] = 'Unusual upload pattern detected';
        }
        
        // Check for mass downloads
        if ($this->detectMassDownloads($userId)) {
            $alerts[] = 'Mass download activity detected';
        }
        
        // Check for off-hours access
        if ($this->detectOffHoursAccess()) {
            $alerts[] = 'Off-hours access detected';
        }
        
        // Check for geographic anomalies
        if ($this->detectGeographicAnomalies($userId)) {
            $alerts[] = 'Geographic anomaly detected';
        }
        
        if (!empty($alerts)) {
            $this->triggerSecurityAlert($userId, $alerts);
        }
        
        return $alerts;
    }
    
    private function detectUnusualUploads($userId)
    {
        $recentUploads = Cache::get("uploads:{$userId}", 0);
        $threshold = 50; // 50 uploads per hour
        
        return $recentUploads > $threshold;
    }
    
    private function detectMassDownloads($userId)
    {
        $recentDownloads = Cache::get("downloads:{$userId}", 0);
        $threshold = 100; // 100 downloads per hour
        
        return $recentDownloads > $threshold;
    }
    
    private function detectOffHoursAccess()
    {
        $hour = now()->hour;
        return $hour < 6 || $hour > 22; // Outside 6 AM - 10 PM
    }
    
    private function detectGeographicAnomalies($userId)
    {
        $currentIp = request()->ip();
        $lastKnownIp = Cache::get("last_ip:{$userId}");
        
        if ($lastKnownIp && $currentIp !== $lastKnownIp) {
            // Check if IPs are from different countries
            return $this->areIpsFromDifferentCountries($currentIp, $lastKnownIp);
        }
        
        Cache::put("last_ip:{$userId}", $currentIp, now()->addDays(7));
        
        return false;
    }
    
    private function triggerSecurityAlert($userId, $alerts)
    {
        \Log::warning('Security alert triggered', [
            'user_id' => $userId,
            'alerts' => $alerts,
            'ip_address' => request()->ip(),
            'timestamp' => now()->toISOString(),
        ]);
        
        // Send notification to security team
        // Implement your notification logic here
    }
}
```

---

## Incident Response

### Security Incident Handler

```php
<?php

namespace App\Services;

class SecurityIncidentHandler
{
    public function handleSecurityBreach($incidentType, $details)
    {
        // 1. Immediate containment
        $this->containBreach($incidentType, $details);
        
        // 2. Assessment
        $impact = $this->assessImpact($incidentType, $details);
        
        // 3. Notification
        $this->notifyStakeholders($incidentType, $impact);
        
        // 4. Investigation
        $this->startInvestigation($incidentType, $details);
        
        // 5. Recovery
        $this->initiateRecovery($incidentType);
        
        // 6. Documentation
        $this->documentIncident($incidentType, $details, $impact);
        
        return true;
    }
    
    private function containBreach($incidentType, $details)
    {
        switch ($incidentType) {
            case 'unauthorized_access':
                // Disable compromised accounts
                $this->disableCompromisedAccounts($details['user_ids'] ?? []);
                break;
                
            case 'data_exfiltration':
                // Block suspicious IPs
                $this->blockSuspiciousIps($details['ip_addresses'] ?? []);
                break;
                
            case 'malware_detected':
                // Quarantine affected files
                $this->quarantineFiles($details['file_paths'] ?? []);
                break;
        }
    }
    
    private function assessImpact($incidentType, $details)
    {
        return [
            'severity' => $this->calculateSeverity($incidentType, $details),
            'affected_users' => $this->getAffectedUsers($details),
            'compromised_data' => $this->getCompromisedData($details),
            'estimated_cost' => $this->estimateCost($incidentType),
        ];
    }
    
    private function disableCompromisedAccounts($userIds)
    {
        foreach ($userIds as $userId) {
            \App\Models\User::find($userId)?->update(['is_active' => false]);
            
            \Log::critical('Account disabled due to security incident', [
                'user_id' => $userId,
                'disabled_at' => now()->toISOString(),
            ]);
        }
    }
    
    private function quarantineFiles($filePaths)
    {
        foreach ($filePaths as $filePath) {
            // Move to quarantine bucket/directory
            $quarantinePath = 'quarantine/' . basename($filePath) . '.' . time();
            
            Storage::disk('oci')->move($filePath, $quarantinePath);
            
            \Log::critical('File quarantined', [
                'original_path' => $filePath,
                'quarantine_path' => $quarantinePath,
                'quarantined_at' => now()->toISOString(),
            ]);
        }
    }
}
```

---

## References

- [Authentication Setup](AUTHENTICATION.md)
- [Configuration Guide](CONFIGURATION.md)
- [Troubleshooting Guide](TROUBLESHOOTING.md)
- [Deployment Guide](DEPLOYMENT.md) 