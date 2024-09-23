<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\StatusReport;

use Syde\Vendor\Inpsyde\Modularity\Module\ServiceModule;
use Syde\Vendor\Inpsyde\Modularity\Module\ExecutableModule;
use Syde\Vendor\Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Syde\Vendor\Psr\Container\ContainerInterface;
/**
 * @psalm-import-type StatusReportItem from Renderer
 */
class StatusReportModule implements ServiceModule, ExecutableModule
{
    use ModuleClassNameIdTrait;
    /**
     * @var array<string, callable>
     * @psalm-var array<string, callable(ContainerInterface): mixed>
     */
    protected $services;
    public function __construct()
    {
        $moduleRootDir = dirname(__FILE__, 2);
        $this->services = (require "{$moduleRootDir}/inc/services.php")();
    }
    /**
     * @inheritDoc
     */
    public function id(): string
    {
        return 'payoneer-status-report';
    }
    /**
     * @inheritDoc
     */
    public function services(): array
    {
        return $this->services;
    }
    public function run(ContainerInterface $container): bool
    {
        add_action('woocommerce_system_status_report', static function () use ($container) {
            $statusReportRenderer = $container->get('status-report.renderer');
            assert($statusReportRenderer instanceof Renderer);
            /** @var StatusReportItem[] $statusReportItems */
            $statusReportItems = $container->get('status-report.fields');
            echo wp_kses_post((string) $statusReportRenderer->render(esc_html__('Payoneer Checkout', 'payoneer-checkout'), $statusReportItems));
        });
        return \true;
    }
}
