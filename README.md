# Stacktracer Symfony Bundle

Lightweight error tracking and tracing SDK for Symfony applications. Capture exceptions, request traces, and performance data.

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
```

### 2. Create configuration file

Create `config/packages/stacktracer.yaml`:

```yaml
stacktracer:
    transport:
        endpoint: '%env(STACKTRACER_ENDPOINT)%'
        api_key: '%env(STACKTRACER_API_KEY)%'
```

That's it! The bundle will automatically:
- Capture all unhandled exceptions
- Track request performance
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
```

## Manual Usage

Inject the service and use it directly:

```php
use Stacktracer\SymfonyBundle\Service\TracingService;
use Stacktracer\SymfonyBundle\Model\Trace;

class MyController
{
    public function __construct(
        private TracingService $stacktracer
    ) {}
    
    public function someAction()
    {
        // Add breadcrumbs for debugging
        $this->stacktracer->addBreadcrumb('user', 'User clicked checkout');
        
        try {
            // Your code
        } catch (\Exception $e) {
            // Manually capture an exception
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

## API Reference

### TracingService

| Method | Description |
|--------|-------------|
| `captureException($e, $context)` | Capture an exception |
| `captureMessage($msg, $level, $context)` | Capture a custom message |
| `addBreadcrumb($category, $message, $data)` | Add debugging breadcrumb |
| `setTag($key, $value)` | Set global tag |
| `setContext($key, $value)` | Set global context |
| `flush()` | Force send queued traces |

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
