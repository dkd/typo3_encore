<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_encore" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use Ssch\Typo3Encore\Integration\FixedIdGenerator;
use Ssch\Typo3Encore\Integration\IdGenerator;
use Ssch\Typo3Encore\Integration\IdGeneratorInterface;
use Ssch\Typo3Encore\Integration\TypoScriptFrontendControllerEventListener;
use Ssch\Typo3Encore\Integration\TypoScriptIncludesEventListener;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;
use TYPO3\CMS\Frontend\Event\AfterTypoScriptDeterminedEvent;

return static function (ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void {
    $services = $containerConfigurator->services();
    $services->defaults()
        ->public()
        ->autowire()
        ->autoconfigure();

    $services->load('Ssch\\Typo3Encore\\', __DIR__ . '/../Classes/')->exclude([
        __DIR__ . '/../Classes/ValueObject',
        __DIR__ . '/../Classes/Asset/EntrypointLookup.php',
    ]);

    $services->alias(IdGeneratorInterface::class, IdGenerator::class);
    $services->set(FixedIdGenerator::class)->args(['fixed']);
    $services->set(TypoScriptFrontendControllerEventListener::class)->tag('event.listener', [
        'event' => AfterCacheableContentIsGeneratedEvent::class,
    ]);

    // TYPO3 v14 routes every TypoScript include through SystemResourceFactory before the
    // legacy PageRendererHooks render-preProcess hook can see it; "typo3_encore:" pseudo
    // paths get filtered out as un-resolvable and silently dropped. Pre-resolve them via
    // an AfterTypoScriptDeterminedEvent listener so the assets reach the PageRenderer.
    if ((new Typo3Version())->getMajorVersion() >= 14) {
        $services->set(TypoScriptIncludesEventListener::class)->tag('event.listener', [
            'event' => AfterTypoScriptDeterminedEvent::class,
        ]);
    }

    if (Environment::getContext()->isTesting()) {
        $services->alias(IdGeneratorInterface::class, FixedIdGenerator::class);
    }
};
