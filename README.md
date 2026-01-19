# Stacktracer Symfony Bundle

Lightweight error tracking and tracing SDK for Symfony applications with **OpenTelemetry-compatible distributed tracing**. Capture exceptions, request traces, spans, breadcrumbs, logs, and performance data - all unified and linked together.

## Features

- 🔗 **Unified Tracing** - Spans, breadcrumbs, logs, and stack traces linked by trace/span IDs
- 🌐 **Distributed Tracing** - W3C Trace Context (traceparent) header support
- 🔍 **OTEL Compatible** - OpenTelemetry-style spans with semantic conventions
- 🔐 **Fingerprinting** - Hash-based deduplication for cost optimization
- 📊 **Monolog Integration** - Automatic log capture linked to spans
- ⚡ **Performance** - Batching, compression, and sampling
- 🧩 **Symfony Integrations** - Doctrine, Messenger, Mailer, Security, Forms, Twig, Cache, Console

## Feature Tiers

| Feature | Tier | Default |
|---------|------|---------|
| Exceptions & Stack Traces | **Free** | ✅ Enabled |
| Logs | **Free** | ✅ Enabled |
| Breadcrumbs | **Free** | ✅ Enabled |
| Request/Response Data | **Free** | ✅ Enabled |
| OTEL Spans | **Paid** | ❌ Disabled |

To enable OTEL spans (paid feature):

```yaml
stacktracer:
    capture:
        spans: true
```

## Installation

```bash
composer require stacktracer/stacktracer-symfony
```

## Quick Setup

### 1. Add environment variables

Add to your `.env` file:

```dotenv
STACKTRACER_ENDPOINT=https://api.stacktracer.io/v1/traces
STACKTRACER_API_KEY=your-api-key-here
STACKTRACER_SERVICE_NAME=my-service
STACKTRACER_SERVICE_VERSION=1.0.0
```

### 2. Create configuration file

Create `config/packages/stacktracer.yaml`:

```yaml
stacktracer:
    transport:
        endpoint: '%env(STACKTRACER_ENDPOINT)%'
        api_key: '%env(STACKTRACER_API_KEY)%'
    service:
        name: '%env(STACKTRACER_SERVICE_NAME)%'
        version: '%env(STACKTRACER_SERVICE_VERSION)%'
```

That's it! The bundle will automatically:
- Capture all unhandled exceptions with fingerprinting
- Track request performance with OTEL spans
- Capture Monolog logs linked to spans
- Propagate trace context for distributed tracing
- Send traces to your Stacktracer endpoint

## Configuration Options

```yaml
stacktracer:
    enabled: true                    # Enable/disable globally
    
    transport:
        endpoint: '%env(STACKTRACER_ENDPOINT)%'  # Required
        api_key: '%env(STACKTRACER_API_KEY)%'    # Required
        batch_size: 50               # Traces per batch
        flush_interval_ms: 5000      # Max wait before flush
        max_queue_size: 500          # Queue size limit
        timeout: 5                   # HTTP timeout (seconds)
        compress: true               # Gzip compression
        max_retries: 3               # Retry attempts
    
    exclude_patterns:                # Paths to ignore
        - '#^/_profiler#'
        - '#^/_wdt#'
    
    capture:
        request: true                # Track requests
        exception: true              # Track exceptions
        exception_context_lines: 5   # Code lines around error
        stacktrace_context_lines: 5  # Code lines per frame
        spans: false                 # 💰 OTEL spans (paid feature, disabled by default)
    
    performance:
        sample_rate: 1.0             # 0.1 = 10% sampling
        max_stack_frames: 50         # Stack depth limit
        capture_code_context: true   # Include code snippets
        filter_vendor_frames: true   # Collapse vendor frames
        capture_request_headers: true
        sensitive_keys:              # Keys to redact
            - password
            - token
            - secret
    
    # OTEL service identification
    service:
        name: '%env(STACKTRACER_SERVICE_NAME)%'
        version: '%env(STACKTRACER_SERVICE_VERSION)%'
    
    # Monolog integration
    logging:
        enabled: true                # Capture logs
        level: debug                 # Minimum level
        capture_context: true        # Include log context
        exclude_channels:            # Channels to ignore
            - event
            - doctrine
```

## Manual Usage

Inject the service and use it directly:

```php
use Stacktracer\SymfonyBundle\Service\TracingService;
use Stacktracer\SymfonyBundle\Model\Trace;
use Stacktracer\SymfonyBundle\Model\Span;

class MyController
{
    public function __construct(
        private TracingService $stacktracer
    ) {}
    
    public function someAction()
    {
        // Add breadcrumbs for debugging (linked to current span)
        $this->stacktracer->addBreadcrumb('user', 'User clicked checkout');
        
        // Create a span for a unit of work
        $this->stacktracer->withSpan('process-order', function(Span $span) {
            $span->setAttribute('order.id', $orderId);
            
            // Nested span for database query
            $this->stacktracer->withSpan('db.query', function($dbSpan) {
                $dbSpan->setAttribute('db.statement', 'SELECT * FROM orders');
                // ... database work
            }, Span::KIND_CLIENT);
            
            // ... process order
        });
        
        try {
            // Your code
        } catch (\Exception $e) {
            // Capture exception (auto-linked to current span)
            $this->stacktracer->captureException($e, [
                'order_id' => $orderId,
            ]);
        }
        
        // Capture a custom message
        $this->stacktracer->captureMessage(
            'Order completed',
            Trace::LEVEL_INFO,
            ['order_id' => $orderId]
        );
    }
}
```

## Feature Flags & Experiments

Monitor errors as you roll out features or run A/B tests. Feature flags are linked to errors, helping identify if a feature introduced issues.

```php
use Stacktracer\SymfonyBundle\Model\FeatureFlag;

class MyController
{
    public function __construct(
        private TracingService $stacktracer
    ) {}
    
    public function checkout()
    {
        // Declare a single feature flag with variant
        $this->stacktracer->addFeatureFlag('Checkout button color', 'Blue');
        $this->stacktracer->addFeatureFlag('New checkout flow');
        
        // Declare multiple feature flags at once
        $this->stacktracer->addFeatureFlags([
            new FeatureFlag('Special offer', 'Free Coffee'),
            new FeatureFlag('Premium shipping'),
        ]);
        
        // Remove flags when features are deactivated
        $this->stacktracer->clearFeatureFlag('Checkout button color');
        
        // Clear all flags
        $this->stacktracer->clearFeatureFlags();
    }
}
```

### LaunchDarkly Integration

```php
$featureEnabled = $launchDarklyClient->variation('bool-flag-key', $user, false);

if ($featureEnabled) {
    $this->stacktracer->addFeatureFlag('bool-flag-key');
} else {
    $this->stacktracer->clearFeatureFlag('bool-flag-key');
}

// String variant
$variant = $launchDarklyClient->variation('button-color', $user, 'default');
$this->stacktracer->addFeatureFlag('button-color', $variant);
```

### Split Integration

```php
// Use an impression listener to automatically track experiments
class StacktracerImpressionListener implements \SplitIO\Sdk\ImpressionListener
{
    public function __construct(private TracingService $stacktracer) {}

    public function logImpression($data)
    {
        $this->stacktracer->addFeatureFlag(
            $data['impression']['feature'],
            $data['impression']['treatment']
        );
    }
}
```

## Distributed Tracing

The bundle automatically propagates trace context via W3C `traceparent` header:

```php
// Outgoing HTTP request - include trace context
$client->request('GET', '/api/users', [
    'headers' => [
        'traceparent' => $this->stacktracer->getTraceparent(),
    ],
]);
```

Incoming requests with `traceparent` headers are automatically linked to the parent trace.

## Symfony Integrations

All integrations are **enabled by default** and auto-detect component availability. Configure in `stacktracer.yaml`:

```yaml
stacktracer:
    integrations:
        doctrine:
            enabled: true
            slow_query_threshold: 100  # ms
        http_client:
            enabled: true
            propagate_context: true    # W3C traceparent
        messenger:
            enabled: true
        cache:
            enabled: true
        console:
            enabled: true
        form:
            enabled: true
        security:
            enabled: true
        mailer:
            enabled: true
        twig:
            enabled: true
            slow_threshold: 50         # ms
```

### Available Integrations

| Integration | Component | What's Tracked |
|-------------|-----------|----------------|
| **Doctrine DBAL** | `doctrine/dbal` | SQL queries, slow query detection (`db.*`) |
| **HTTP Client** | `symfony/http-client` | Outgoing requests, trace propagation (`http.*`) |
| **Messenger** | `symfony/messenger` | Async jobs, retries, failures (`messaging.*`, `job.*`) |
| **Cache** | `symfony/cache` | Cache hits/misses, operations (`cache.*`) |
| **Console** | `symfony/console` | CLI commands, exit codes, memory (`cli.*`) |
| **Form** | `symfony/form` | Validation errors, failed submissions (`form.*`) |
| **Security** | `symfony/security` | Login, logout, access denied (`security.*`) |
| **Mailer** | `symfony/mailer` | Email sending, delivery failures (`mail.*`) |
| **Twig** | `twig/twig` | Template renders, errors (`template.*`) |

### Example: Form Validation Errors

Form validation errors are automatically captured as breadcrumbs:

```php
// When a form fails validation, you'll see breadcrumbs like:
// [warning] Form validation failed
//   - form.name: "contact"
//   - form.error_count: 2
//   - form.errors: [{"field": "email", "message": "Invalid email"}]
```

### Example: Security Events

Authentication events are tracked automatically:

```php
// Login failures appear as breadcrumbs + spans:
// [warning] Login attempt failed
//   - security.event: "login_failure"
//   - security.attempted_user: "john@example.com"
//   - security.failure_reason: "Invalid credentials"
```

### Example: Mailer Tracking

Email sending is tracked with delivery status:

```php
// Email events appear as breadcrumbs + spans:
// [info] Email sent successfully
//   - mail.to: "user@example.com"
//   - mail.subject: "Welcome to our platform"
//   - mail.duration_ms: 234.5
```

## Data Model

### Unified Linking

All data is linked together for easy frontend navigation:

```
Trace
├── trace_id (OTEL 128-bit)
├── spans[]
│   ├── span_id (OTEL 64-bit)
│   ├── parent_span_id
│   ├── breadcrumbs[] (linked by span_id)
│   ├── logs[] (linked by span_id)
│   └── stack_trace[] (with fingerprints)
├── fingerprint (for deduplication)
└── group_key (for aggregation)
```

### Fingerprinting

Stack traces and errors are fingerprinted for:
- **Deduplication** - Identical errors grouped together
- **Cost Optimization** - Store fingerprint, not full stack on repeats
- **Pattern Detection** - Find similar error patterns

```php
// Fingerprints are computed automatically
$trace->getFingerprint();     // Unique error signature
$trace->getGroupKey();        // Grouping key for similar errors
```

## API Reference

### TracingService

| Method | Description |
|--------|-------------|
| `captureException($e, $context)` | Capture an exception |
| `captureMessage($msg, $level, $context)` | Capture a custom message |
| `addBreadcrumb($cat, $msg, $data, $level)` | Add debugging breadcrumb |
| `startSpan($name, $kind)` | Start a new span |
| `endSpan($span)` | End a span |
| `withSpan($name, $callback, $kind)` | Execute within a span |
| `getCurrentSpan()` | Get active span |
| `getTraceparent()` | Get W3C traceparent header |
| `setIncomingContext($traceparent)` | Set context from incoming header |
| `log($message, $level, $context)` | Add a log entry |
| `setTag($key, $value)` | Set global tag |
| `setContext($key, $value)` | Set global context |
| `flush()` | Force send queued traces |

### Span Kinds (OTEL)

- `Span::KIND_INTERNAL` - Internal operation (default)
- `Span::KIND_SERVER` - Server handling request
- `Span::KIND_CLIENT` - Client making request
- `Span::KIND_PRODUCER` - Message producer
- `Span::KIND_CONSUMER` - Message consumer

### Trace Levels

- `Trace::LEVEL_DEBUG`
- `Trace::LEVEL_INFO`
- `Trace::LEVEL_WARNING`
- `Trace::LEVEL_ERROR`
- `Trace::LEVEL_FATAL`

## Requirements

- PHP 8.1+
- Symfony 6.4, 7.x, or 8.x
- ext-curl
- ext-json

## License

MIT
