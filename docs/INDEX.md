# Laravel OCI Driver Documentation

Welcome to the comprehensive documentation for the Laravel OCI Driver package. This documentation will help you get started, configure, and make the most of Oracle Cloud Infrastructure Object Storage integration with Laravel.

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

## 📚 Documentation Overview

### 🚀 Getting Started

Start here if you're new to the package or Oracle Cloud Infrastructure.

| Document                                  | Description                                | Audience           |
| ----------------------------------------- | ------------------------------------------ | ------------------ |
| **[README](../README.md)**                | Main package overview and quick start      | Everyone           |
| **[Installation Guide](INSTALLATION.md)** | Detailed installation for all environments | Developers, DevOps |

### ⚙️ Configuration & Setup

Deep dive into configuration options and authentication methods.

| Document                                      | Description                           | Audience           |
| --------------------------------------------- | ------------------------------------- | ------------------ |
| **[Configuration Guide](CONFIGURATION.md)**   | Comprehensive configuration reference | Developers         |
| **[Authentication Setup](AUTHENTICATION.md)** | OCI authentication and key management | Developers, DevOps |

### 📖 Usage & Development

Learn how to use the package effectively in your applications.

| Document                              | Description                     | Audience   |
| ------------------------------------- | ------------------------------- | ---------- |
| **[API Reference](API_REFERENCE.md)** | Complete API documentation      | Developers |
| **[Usage Examples](EXAMPLES.md)**     | Practical code examples         | Developers |
| **[Testing Guide](TESTING.md)**       | Testing strategies and examples | Developers |

### 🔧 Troubleshooting & Maintenance

Solutions for common issues and optimization techniques.

| Document                                        | Description                 | Audience               |
| ----------------------------------------------- | --------------------------- | ---------------------- |
| **[Troubleshooting Guide](TROUBLESHOOTING.md)** | Common issues and solutions | Everyone               |
| **[Performance Guide](PERFORMANCE.md)**         | Optimization and tuning     | Developers, DevOps     |
| **[Security Guide](SECURITY.md)**               | Security best practices     | Security Teams, DevOps |

### 🚀 Advanced Topics

Advanced features and enterprise-level usage.

| Document                              | Description                            | Audience               |
| ------------------------------------- | -------------------------------------- | ---------------------- |
| **[Advanced Features](ADVANCED.md)**  | Storage tiers, bulk operations, events | Advanced Developers    |
| **[Migration Guide](MIGRATION.md)**   | Migrating from other storage providers | DevOps, Architects     |
| **[Deployment Guide](DEPLOYMENT.md)** | Production deployment strategies       | DevOps, Infrastructure |

## 🎯 Quick Navigation

### By Use Case

#### **New to Laravel OCI Driver?**

1. Read the [README](../README.md) for overview
2. Follow [Installation Guide](INSTALLATION.md)
3. Complete [Configuration Guide](CONFIGURATION.md)
4. Try [Usage Examples](EXAMPLES.md)

#### **Setting Up Authentication?**

1. [Authentication Setup](AUTHENTICATION.md)
2. [Security Guide](SECURITY.md)
3. [Troubleshooting Guide](TROUBLESHOOTING.md) → Authentication Issues

#### **Having Issues?**

1. [Troubleshooting Guide](TROUBLESHOOTING.md)
2. [API Reference](API_REFERENCE.md) for method documentation
3. [GitHub Issues](https://github.com/mohamedhabibwork/laravel-oci-driver/issues)

#### **Optimizing Performance?**

1. [Performance Guide](PERFORMANCE.md)
2. [Advanced Features](ADVANCED.md)
3. [Configuration Guide](CONFIGURATION.md) → Performance Options

#### **Deploying to Production?**

1. [Deployment Guide](DEPLOYMENT.md)
2. [Security Guide](SECURITY.md)
3. [Installation Guide](INSTALLATION.md) → Production Deployment

### By Role

#### **Developers**

Essential documentation for implementing OCI storage in your Laravel applications.

-   **Start Here**: [README](../README.md) → [Installation](INSTALLATION.md)
-   **Core Usage**: [API Reference](API_REFERENCE.md) → [Examples](EXAMPLES.md)
-   **Testing**: [Testing Guide](TESTING.md)
-   **Troubleshooting**: [Troubleshooting Guide](TROUBLESHOOTING.md)

#### **DevOps Engineers**

Infrastructure and deployment focused documentation.

-   **Installation**: [Installation Guide](INSTALLATION.md) → Production Deployment
-   **Configuration**: [Configuration Guide](CONFIGURATION.md)
-   **Security**: [Security Guide](SECURITY.md)
-   **Deployment**: [Deployment Guide](DEPLOYMENT.md)
-   **Monitoring**: [Performance Guide](PERFORMANCE.md)

#### **System Administrators**

Security and maintenance focused documentation.

-   **Security**: [Security Guide](SECURITY.md)
-   **Authentication**: [Authentication Setup](AUTHENTICATION.md)
-   **Troubleshooting**: [Troubleshooting Guide](TROUBLESHOOTING.md)
-   **Performance**: [Performance Guide](PERFORMANCE.md)

#### **Architects**

High-level design and integration documentation.

-   **Overview**: [README](../README.md)
-   **Architecture**: [Advanced Features](ADVANCED.md)
-   **Migration**: [Migration Guide](MIGRATION.md)
-   **Performance**: [Performance Guide](PERFORMANCE.md)

## 📋 Documentation Status

| Document                                    | Status         | Last Updated | Completeness |
| ------------------------------------------- | -------------- | ------------ | ------------ |
| [README](../README.md)                      | ✅ Complete    | Latest       | 100%         |
| [Installation Guide](INSTALLATION.md)       | ✅ Complete    | Latest       | 100%         |
| [API Reference](API_REFERENCE.md)           | ✅ Complete    | Latest       | 100%         |
| [Troubleshooting Guide](TROUBLESHOOTING.md) | ✅ Complete    | Latest       | 100%         |
| [Configuration Guide](CONFIGURATION.md)     | 🚧 In Progress | -            | 75%          |
| [Examples Guide](EXAMPLES.md)               | 🚧 In Progress | -            | 60%          |
| [Testing Guide](TESTING.md)                 | 📋 Planned     | -            | 0%           |
| [Performance Guide](PERFORMANCE.md)         | 📋 Planned     | -            | 0%           |
| [Security Guide](SECURITY.md)               | 📋 Planned     | -            | 0%           |
| [Advanced Features](ADVANCED.md)            | 📋 Planned     | -            | 0%           |
| [Migration Guide](MIGRATION.md)             | 📋 Planned     | -            | 0%           |
| [Deployment Guide](DEPLOYMENT.md)           | 📋 Planned     | -            | 0%           |

## 🛠️ Package Information

### Current Version

-   **Package**: `mohamedhabibwork/laravel-oci-driver`
-   **Version**: Latest
-   **Laravel**: 11.x
-   **PHP**: 8.2+

### Links

-   **GitHub Repository**: [mohamedhabibwork/laravel-oci-driver](https://github.com/mohamedhabibwork/laravel-oci-driver)
-   **Packagist**: [mohamedhabibwork/laravel-oci-driver](https://packagist.org/packages/mohamedhabibwork/laravel-oci-driver)
-   **Issues**: [GitHub Issues](https://github.com/mohamedhabibwork/laravel-oci-driver/issues)
-   **Discussions**: [GitHub Discussions](https://github.com/mohamedhabibwork/laravel-oci-driver/discussions)

## 🤝 Contributing to Documentation

We welcome contributions to improve our documentation! Here's how you can help:

### Reporting Documentation Issues

-   **Missing Information**: [Open an issue](https://github.com/mohamedhabibwork/laravel-oci-driver/issues/new) describing what's missing
-   **Incorrect Information**: [Create an issue](https://github.com/mohamedhabibwork/laravel-oci-driver/issues/new) with the correct information
-   **Unclear Sections**: [Start a discussion](https://github.com/mohamedhabibwork/laravel-oci-driver/discussions) about how to improve clarity

### Contributing Documentation

1. **Fork the repository**
2. **Create a documentation branch**: `git checkout -b docs/improve-installation-guide`
3. **Make your changes** to the relevant markdown files
4. **Follow the documentation style guide** (see below)
5. **Submit a pull request** with a clear description of your changes

### Documentation Style Guide

#### Formatting

-   Use **Markdown** for all documentation
-   Use `code blocks` for code examples
-   Use **bold** for important terms
-   Use _italics_ for emphasis
-   Use > blockquotes for important notes

#### Structure

-   Start with a clear **Table of Contents**
-   Use descriptive **headings** (##, ###)
-   Include **code examples** for all features
-   Add **cross-references** to related documentation
-   End with **links to related resources**

#### Code Examples

-   Provide **complete, runnable examples**
-   Include **comments** explaining complex code
-   Show **both success and error scenarios**
-   Use **realistic example data**
-   Test all code examples before publishing

#### Language

-   Use **clear, concise language**
-   Write for your **target audience**
-   **Explain technical terms** when first introduced
-   Use **active voice** where possible
-   Provide **step-by-step instructions**

## 📞 Getting Help

### Documentation Feedback

-   **General Questions**: [GitHub Discussions](https://github.com/mohamedhabibwork/laravel-oci-driver/discussions)
-   **Documentation Issues**: [GitHub Issues](https://github.com/mohamedhabibwork/laravel-oci-driver/issues)
-   **Feature Requests**: [GitHub Issues](https://github.com/mohamedhabibwork/laravel-oci-driver/issues/new?template=feature_request.md)

### Technical Support

-   **Bug Reports**: [GitHub Issues](https://github.com/mohamedhabibwork/laravel-oci-driver/issues/new?template=bug_report.md)
-   **Security Issues**: [security@mohamedhabib.work](mailto:security@mohamedhabib.work)
-   **Commercial Support**: [business@mohamedhabib.work](mailto:business@mohamedhabib.work)

### Community

-   **GitHub Discussions**: Ask questions and share experiences
-   **Stack Overflow**: Use tag `laravel-oci-driver`
-   **Laravel Community**: General Laravel support channels

## 🏷️ Document Tags

Use these tags to quickly find relevant documentation:

-   `#getting-started` - For new users
-   `#configuration` - Configuration and setup
-   `#authentication` - OCI authentication
-   `#api-reference` - Method documentation
-   `#troubleshooting` - Problem solving
-   `#performance` - Optimization
-   `#security` - Security practices
-   `#deployment` - Production deployment
-   `#examples` - Code examples
-   `#advanced` - Advanced features

---

## 📝 License

This documentation is part of the Laravel OCI Driver package and is licensed under the [MIT License](../LICENSE.md).

---

<div align="center">

**Laravel OCI Driver Documentation**

Made with ❤️ for the Laravel community

[⭐ Star on GitHub](https://github.com/mohamedhabibwork/laravel-oci-driver) | [📚 Full Documentation](../README.md) | [🐛 Report Issues](https://github.com/mohamedhabibwork/laravel-oci-driver/issues)

</div>
