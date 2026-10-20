<?php

declare(strict_types=1);

namespace Agung\EventIntegration;

use Agung\EventIntegration\Contracts\RabbitMqConnectionResolver;
use Agung\EventIntegration\Contracts\WebhookUrlValidator;
use Agung\EventIntegration\Engine\IntegrationEngine;
use Agung\EventIntegration\Engine\PayloadCompiler;
use Agung\EventIntegration\Events\EventRegistry;
use Agung\EventIntegration\Filters\FilterOperatorRegistry;
use Agung\EventIntegration\Filters\Operators\ContainsOperator;
use Agung\EventIntegration\Filters\Operators\EqualsOperator;
use Agung\EventIntegration\Filters\Operators\GreaterThanOperator;
use Agung\EventIntegration\Filters\Operators\IsEmptyOperator;
use Agung\EventIntegration\Filters\Operators\IsNotEmptyOperator;
use Agung\EventIntegration\Filters\Operators\IsOneOfOperator;
use Agung\EventIntegration\Filters\Operators\LessThanOperator;
use Agung\EventIntegration\Filters\PayloadFilter;
use Agung\EventIntegration\Formatters\FormatterRegistry;
use Agung\EventIntegration\Formatters\Formatters\DefaultFormatter;
use Agung\EventIntegration\Formatters\Formatters\FloatFormatter;
use Agung\EventIntegration\Formatters\Formatters\IntegerFormatter;
use Agung\EventIntegration\Formatters\Formatters\StringFormatter;
use Agung\EventIntegration\Templates\EachTemplate;
use Agung\EventIntegration\Templates\MapTemplate;
use Agung\EventIntegration\Templates\MaxTemplate;
use Agung\EventIntegration\Templates\TemplateRegistry;
use Agung\EventIntegration\Transports\ConfiguredRabbitMqConnectionResolver;
use Agung\EventIntegration\Transports\ConfiguredWebhookUrlValidator;
use Agung\EventIntegration\Transports\IntegrationPublisher;
use Agung\EventIntegration\Transports\RabbitMqPublisher;
use Agung\EventIntegration\Transports\TransportPublisherRegistry;
use Agung\EventIntegration\Transports\WebhookPublisher;
use Illuminate\Support\ServiceProvider;

final class EventIntegrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/event-integration.php' => config_path('event-integration.php'),
            ], 'event-integration-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'event-integration-migrations');
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/event-integration.php', 'event-integration');

        $this->app->singleton(EventRegistry::class);
        $this->app->singleton(FilterOperatorRegistry::class, function (): FilterOperatorRegistry {
            return new FilterOperatorRegistry([
                new EqualsOperator,
                new IsOneOfOperator,
                new ContainsOperator,
                new GreaterThanOperator,
                new LessThanOperator,
                new IsEmptyOperator,
                new IsNotEmptyOperator,
            ]);
        });

        $this->app->singleton(TemplateRegistry::class, function (): TemplateRegistry {
            return new TemplateRegistry([
                new EachTemplate((int) config('event-integration.compiler.max_messages', 1000)),
                new MapTemplate,
                new MaxTemplate,
            ]);
        });

        $this->app->singleton(FormatterRegistry::class, function (): FormatterRegistry {
            return new FormatterRegistry([
                new DefaultFormatter,
                new StringFormatter,
                new IntegerFormatter,
                new FloatFormatter,
            ]);
        });

        $this->app->singleton(PayloadFilter::class);
        $this->app->singleton(PayloadCompiler::class, function (): PayloadCompiler {
            return new PayloadCompiler(
                $this->app->make(TemplateRegistry::class),
                $this->app->make(FormatterRegistry::class),
                (int) config('event-integration.compiler.max_messages', 1000),
                (int) config('event-integration.compiler.max_depth', 64),
            );
        });
        $this->app->singleton(IntegrationEngine::class);
        $this->app->singleton(
            RabbitMqConnectionResolver::class,
            ConfiguredRabbitMqConnectionResolver::class,
        );
        $this->app->singleton(WebhookUrlValidator::class, ConfiguredWebhookUrlValidator::class);
        $this->app->singleton(TransportPublisherRegistry::class, function (): TransportPublisherRegistry {
            return new TransportPublisherRegistry([
                new WebhookPublisher($this->app->make(WebhookUrlValidator::class)),
                new RabbitMqPublisher($this->app->make(RabbitMqConnectionResolver::class)),
            ]);
        });
        $this->app->singleton(IntegrationPublisher::class);
    }
}
