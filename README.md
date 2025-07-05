# Laravel OCI Driver

<p align="center">
  <img src="https://placehold.co/900x120/22223a/ffffff?text=Laravel+OCI+Driver+%7C+OCI+%E2%9E%9C+Laravel+Filesystem+%7C+Full+Docs+%F0%9F%93%9A+%7C+OCI+Cloud+Animation" alt="Laravel OCI Driver Banner" />
</p>

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mohamedhabibwork/laravel-oci-driver.svg?style=flat-square)](https://packagist.org/packages/mohamedhabibwork/laravel-oci-driver)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mohamedhabibwork/laravel-oci-driver/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mohamedhabibwork/laravel-oci-driver/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/mohamedhabibwork/laravel-oci-driver/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/mohamedhabibwork/laravel-oci-driver/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/mohamedhabibwork/laravel-oci-driver.svg?style=flat-square)](https://packagist.org/packages/mohamedhabibwork/laravel-oci-driver)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg?style=flat-square)](https://phpstan.org/)

---

## 🛠️ How to Install

```bash
composer require mohamedhabibwork/laravel-oci-driver
```

---

## 🚀 Implemented Features

- **Artisan Commands:**
  - `oci:setup` – Create Oracle-compatible `.oci` folder, generate/copy key files, write config
  - `oci:config` – Interactive configuration for OCI settings, with `--validate` to check config
  - `oci:connection` – Manage multiple OCI connections: `list`, `test`, `switch`, `summary`
  - `oci:status` – Check connection status, test file operations, and list files in a bucket

- **Key Providers:**
  - File-based (from file path)
  - Environment-based (from env variable, supports base64)
  - Custom providers via interface

- **Enums:**
  - `ConnectionType` (primary, secondary, backup, development, testing, staging, production, archive)
  - `StorageTier` (Standard, InfrequentAccess, Archive)
  - `LogLevel` (emergency, alert, critical, error, warning, notice, info, debug)

- **Exception Handling:**
  - `PrivateKeyFileNotFoundException`
  - `SignerValidateException`
  - `SigningValidationFailedException`

- **Configuration:**
  - All required OCI config: tenancy, user, fingerprint, key path, region, namespace, bucket, storage tier
  - Optional: prefix, url, passphrase, advanced performance/cache options, debug/logging

- **Integration:**
  - Laravel filesystem disk driver (`oci`)
  - Event system for file operations (upload, download, delete, etc.)
  - Service provider auto-registers everything
  - Health check and connection validation built-in

---

## ⚡ Quick Start

```php
use Illuminate\Support\Facades\Storage;

// Upload a file
Storage::disk('oci')->put('documents/hello.txt', 'Hello, Oracle Cloud!');

// Download a file
$content = Storage::disk('oci')->get('documents/hello.txt');

// List files
$files = Storage::disk('oci')->files('documents');
```

---

## 📚 Documentation

- [Configuration Guide](docs/CONFIGURATION.md) — All config options, environment variables, and best practices
- [Authentication Setup](docs/AUTHENTICATION.md) — Key management, provider types, and security notes
- [Usage Examples](docs/EXAMPLES.md) — Real-world code snippets for common tasks
- [Testing Guide](docs/TESTING.md) — How to test, recommended tools, and sample tests
- [Performance Guide](docs/PERFORMANCE.md) — Tuning, caching, and large file strategies
- [Security Guide](docs/SECURITY.md) — Best practices, secrets management, and compliance
- [Advanced Features](docs/ADVANCED.md) — Bulk ops, events, custom providers, and more
- [Migration Guide](docs/MIGRATION.md) — Migrate from S3, GCS, or local storage
- [Deployment Guide](docs/DEPLOYMENT.md) — Production deployment strategies
- [Troubleshooting Guide](docs/TROUBLESHOOTING.md) — Common issues and solutions
- [API Reference](docs/API_REFERENCE.md) — Full method signatures and options

---

## 📝 API Reference

See the [API_REFERENCE.md](docs/API_REFERENCE.md) for full method signatures, options, and advanced usage. For configuration, authentication, and advanced features, see the relevant guides above.

---

## 🧪 Testing

```bash
composer test
```

See the [Testing Guide](docs/TESTING.md) for more details and examples.

---

## 🔧 Troubleshooting

- Use `php artisan oci:config --validate` to check your configuration
- Ensure your OCI credentials and key files are correct
- Check file permissions for private keys
- See [TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) for more help

---

## 🤝 Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

---

## 📞 Support

- [GitHub Issues](https://github.com/mohamedhabibwork/laravel-oci-driver/issues)
- [GitHub Discussions](https://github.com/mohamedhabibwork/laravel-oci-driver/discussions)
- Email: [security@mohamedhabib.work](mailto:security@mohamedhabib.work)

---

## 📄 License

The MIT License (MIT). See [LICENSE.md](LICENSE.md) for details.

---

## 🗺️ Roadmap

- [ ] Advanced Health Checks (Spatie Health integration)
- [ ] Connection Pooling and advanced parallel/multipart upload support
- [ ] Custom Event Listeners for all storage operations
- [ ] Improved Error Reporting and user-friendly CLI output
- [ ] Web UI for Connection Management
- [ ] More Key Providers (e.g., HashiCorp Vault, AWS Secrets Manager)
- [ ] Automatic Key Rotation
- [ ] Enhanced Documentation & Examples
- [ ] Support for Additional OCI Services (beyond Object Storage)
- [ ] Performance Benchmarks and Tuning Guides

---

## 👥 Credits

- **[Mohamed Habib](https://github.com/mohamedhabibwork)** - Creator & Lead Developer
- [All Contributors](../../contributors)

---

<div align="center">

**Made with ❤️ for the Laravel community**

[⭐ Star us on GitHub](https://github.com/mohamedhabibwork/laravel-oci-driver) | [📚 Documentation](https://github.com/mohamedhabibwork/laravel-oci-driver/blob/main/README.md) | [🐛 Report Bug](https://github.com/mohamedhabibwork/laravel-oci-driver/issues) | [💡 Request Feature](https://github.com/mohamedhabibwork/laravel-oci-driver/discussions)

</div>
