# GitHub Actions Workflows

This repository uses a comprehensive set of GitHub Actions workflows to ensure code quality, security, and reliable deployments.

## 🚀 Workflow Overview

### Main Workflow (`main.yml`)

The orchestrator workflow that coordinates all quality checks:

-   **Triggers**: Push/PR to `main` or `develop` branches
-   **Features**:
    -   Smart change detection (only runs relevant checks)
    -   Parallel execution of quality gates
    -   Comprehensive status reporting
    -   Pipeline summary generation

### CI Pipeline (`ci.yml`)

Comprehensive testing across multiple PHP and Laravel versions:

-   **Matrix Testing**: PHP 8.2-8.3 × Laravel 11-12
-   **Features**:
    -   Dependency caching for faster builds
    -   Code coverage reporting (Codecov integration)
    -   Minimum version compatibility testing
    -   Test artifact collection on failures
    -   Proper error handling and timeouts

### Static Analysis (`static-analysis.yml`)

Code quality and type safety checks:

-   **PHPStan**: Level 8 analysis with baseline support
-   **Psalm**: Additional static analysis (continue-on-error)
-   **Features**:
    -   Cached dependencies
    -   GitHub-formatted error reporting
    -   Artifact storage for analysis results

### Code Style (`code-style.yml`)

Automated code formatting and style enforcement:

-   **Laravel Pint**: PSR-12 compliance
-   **Features**:
    -   Dry-run checking before fixes
    -   Automatic commit of style fixes
    -   Smart change detection
    -   Detailed status reporting

### Security (`security.yml`)

Comprehensive security scanning:

-   **Composer Audit**: Dependency vulnerability scanning
-   **CodeQL Analysis**: Static security analysis
-   **Dependency Review**: PR-based dependency checking
-   **Features**:
    -   Weekly scheduled scans
    -   Security advisory checking
    -   License compliance verification

### Deployment (`deploy.yml`)

Automated package deployment to Packagist:

-   **Release Validation**: Comprehensive pre-deployment checks
-   **Packagist Integration**: Automatic package updates
-   **Features**:
    -   Manual deployment trigger option
    -   Deployment verification
    -   Comprehensive status reporting
    -   Environment protection

## 🔧 Configuration

### Required Secrets

```bash
# Codecov integration
CODECOV_TOKEN=your_codecov_token

# Packagist deployment
PACKAGIST_USERNAME=your_packagist_username
PACKAGIST_TOKEN=your_packagist_api_token
```

### Branch Protection

Recommended branch protection rules for `main`:

-   Require status checks to pass before merging
-   Require branches to be up to date before merging
-   Required status checks:
    -   `CI Pipeline`
    -   `Static Analysis`
    -   `Code Style`
    -   `Security`

## 📊 Quality Gates

### Automatic Checks

-   ✅ **Tests**: Unit and feature tests with coverage
-   ✅ **Static Analysis**: PHPStan level 8 + Psalm
-   ✅ **Code Style**: Laravel Pint (PSR-12)
-   ✅ **Security**: Dependency audit + CodeQL
-   ✅ **Dependencies**: Automated updates via Dependabot

### Manual Triggers

-   🚀 **Deploy**: Manual Packagist update via workflow dispatch
-   🔄 **Force Update**: Emergency deployment option

## 🎯 Performance Optimizations

### Caching Strategy

-   **Composer Dependencies**: Cached by PHP version and lock file hash
-   **Build Artifacts**: Stored for debugging failed runs
-   **Analysis Results**: Preserved for trend analysis

### Parallel Execution

-   Multiple workflows run simultaneously
-   Matrix builds execute in parallel
-   Independent quality gates don't block each other

### Smart Triggers

-   Path-based filtering prevents unnecessary runs
-   Change detection optimizes workflow execution
-   Concurrency groups prevent resource conflicts

## 🛠️ Maintenance

### Dependabot Configuration

-   **GitHub Actions**: Weekly updates on Mondays at 09:00
-   **Composer**: Weekly updates on Mondays at 10:00
-   **Auto-merge**: Configured for minor/patch updates
-   **Review Required**: Major version updates

### Monitoring

-   **Workflow Status**: Visible in repository Actions tab
-   **Coverage Trends**: Tracked via Codecov
-   **Security Alerts**: GitHub Security tab
-   **Dependency Health**: Dependabot alerts

## 🚨 Troubleshooting

### Common Issues

#### Tests Failing

1. Check test logs in CI Pipeline workflow
2. Review coverage reports in artifacts
3. Verify PHP/Laravel version compatibility

#### Static Analysis Errors

1. Review PHPStan baseline for new issues
2. Check type annotations and return types
3. Update baseline if necessary: `composer analyse -- --generate-baseline`

#### Code Style Issues

1. Run locally: `composer format`
2. Check Pint configuration in `pint.json`
3. Review auto-committed style fixes

#### Deployment Failures

1. Verify Packagist credentials in secrets
2. Check release tag format
3. Ensure all quality gates pass

### Local Development

```bash
# Run all quality checks locally
composer test          # Run tests
composer analyse        # Static analysis
composer format         # Code formatting

# Coverage report
composer test-coverage
```

## 📈 Metrics & Reporting

### Coverage Reports

-   **Clover XML**: `build/logs/clover.xml`
-   **HTML Report**: `build/coverage/index.html`
-   **JUnit XML**: `build/logs/junit.xml`

### Analysis Reports

-   **PHPStan**: `build/phpstan/`
-   **Psalm**: Console output in workflow logs

### Deployment Logs

-   **Packagist API**: Response logging
-   **Verification**: Package availability checks
-   **Summary**: Deployment status and links

---

## 🤝 Contributing

When contributing to this repository:

1. **Fork & Branch**: Create feature branches from `develop`
2. **Quality Checks**: Ensure all workflows pass
3. **Code Style**: Run `composer format` before committing
4. **Tests**: Add tests for new functionality
5. **Documentation**: Update relevant documentation

The GitHub Actions will automatically:

-   Run all quality checks on your PR
-   Fix code style issues (if any)
-   Provide detailed feedback on any failures
-   Block merging if quality gates fail

For questions about the CI/CD setup, please open an issue with the `ci/cd` label.
