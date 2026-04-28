<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_encore" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Ssch\Typo3Encore\Integration;

use Ssch\Typo3Encore\Asset\EntrypointLookup;
use Ssch\Typo3Encore\Asset\EntrypointLookupInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Event\AfterTypoScriptDeterminedEvent;

/**
 * Pre-resolves "typo3_encore:" entries in the page TypoScript array and registers the
 * actual asset files with the PageRenderer.
 *
 * Background: in TYPO3 v14, RequestHandler::processHtmlBasedRenderingSettings runs every
 * page.includeCSS / includeJS* entry through SystemResourceFactory before forwarding to
 * PageRenderer. The factory rejects "typo3_encore:<entry>" pseudo-paths as
 * un-resolvable and the entry is silently dropped (`catch SystemResourceException ->
 * continue;`). Even calling pageRenderer->addCssFile('typo3_encore:app') directly would
 * fail because that method also runs through SystemResourceFactory in v14.
 *
 * The legacy render-preProcess PageRenderer hook (PageRendererHooks) therefore never
 * sees these entries on v14.
 *
 * This listener intercepts at AfterTypoScriptDeterminedEvent — after TypoScript is fully
 * resolved, well before PageRenderer renders — walks the page array, resolves each
 * "typo3_encore:" entry through entrypoints.json, and calls
 * PageRenderer->addCssFile/addJsFile/addJsLibrary/addJsFooterFile/addJsFooterLibrary
 * with the *concrete* file paths the SystemResourceFactory accepts.
 *
 * Settings are read directly from the FrontendTypoScript setup array because Extbase's
 * ConfigurationManager is not yet request-bound at this point in the middleware chain
 * (request only attaches to ConfigurationManager once an Extbase Bootstrap runs or
 * $GLOBALS['TYPO3_REQUEST'] is set in RequestHandler).
 *
 * Only registered on TYPO3 v14+. On v13, the legacy render-preProcess hook still works
 * because v13 does not run TypoScript include paths through SystemResourceFactory — see
 * Configuration/Services.php.
 */
final class TypoScriptIncludesEventListener
{
    private const ENCORE_PREFIX = 'typo3_encore:';

    /**
     * @var list<string>
     */
    private const CSS_INCLUDE_KEYS = ['includeCSS.', 'includeCSSLibs.'];

    /**
     * Maps TypoScript page array keys to the matching pageRenderer add* method semantics.
     *
     * @var array<string, array{footer: bool, library: bool}>
     */
    private const JS_INCLUDE_MAP = [
        'includeJSlibs.' => ['footer' => false, 'library' => true],
        'includeJSFooterlibs.' => ['footer' => true, 'library' => true],
        'includeJS.' => ['footer' => false, 'library' => false],
        'includeJSFooter.' => ['footer' => true, 'library' => false],
    ];

    public function __construct(
        private readonly PageRenderer $pageRenderer,
        private readonly FilesystemInterface $filesystem,
        private readonly JsonDecoderInterface $jsonDecoder,
    ) {
    }

    public function __invoke(AfterTypoScriptDeterminedEvent $event): void
    {
        $frontendTypoScript = $event->getFrontendTypoScript();
        if (! $frontendTypoScript->hasPage()) {
            return;
        }

        $setupArray = $frontendTypoScript->getSetupArray();
        $encoreSettings = $setupArray['plugin.']['tx_typo3encore.']['settings.'] ?? null;
        if (! is_array($encoreSettings)) {
            return;
        }

        $lookups = $this->buildEntrypointLookups($encoreSettings);
        if ($lookups === []) {
            return;
        }

        $pageArray = $frontendTypoScript->getPageArray();

        foreach (self::CSS_INCLUDE_KEYS as $key) {
            $this->processCssIncludes($pageArray[$key] ?? null, $lookups);
        }

        foreach (self::JS_INCLUDE_MAP as $key => $opts) {
            $this->processJsIncludes($pageArray[$key] ?? null, $lookups, $opts['footer'], $opts['library']);
        }
    }

    /**
     * @param array<string, mixed> $encoreSettings
     * @return array<string, EntrypointLookupInterface>
     */
    private function buildEntrypointLookups(array $encoreSettings): array
    {
        $lookups = [];
        $strictMode = (bool)($encoreSettings['strictMode'] ?? false);

        $defaultPath = $encoreSettings['entrypointJsonPath'] ?? '';
        if (is_string($defaultPath) && $defaultPath !== '' && $this->filesystem->exists($this->filesystem->getFileAbsFileName($defaultPath))) {
            $lookups[EntrypointLookupInterface::DEFAULT_BUILD] = new EntrypointLookup(
                $defaultPath,
                $strictMode,
                $this->jsonDecoder,
                $this->filesystem,
            );
        }

        $builds = $encoreSettings['builds.'] ?? null;
        if (is_array($builds)) {
            foreach ($builds as $buildKey => $buildPath) {
                if (str_ends_with($buildKey, '.') || ! is_string($buildPath) || $buildPath === '') {
                    continue;
                }
                $entrypointsPath = sprintf('%s/entrypoints.json', $buildPath);
                $lookups[$buildKey] = new EntrypointLookup(
                    $entrypointsPath,
                    $strictMode,
                    $this->jsonDecoder,
                    $this->filesystem,
                );
            }
        }

        return $lookups;
    }

    /**
     * @param array<string, EntrypointLookupInterface> $lookups
     */
    private function processCssIncludes(mixed $cssIncludes, array $lookups): void
    {
        if (! is_array($cssIncludes)) {
            return;
        }

        foreach ($cssIncludes as $entryKey => $value) {
            if (! is_string($value) || ! str_starts_with($value, self::ENCORE_PREFIX)) {
                continue;
            }

            $config = $cssIncludes[$entryKey . '.'] ?? [];
            if (! is_array($config)) {
                $config = [];
            }

            [$buildName, $entryName] = $this->resolveBuildAndEntry($value);
            $lookup = $lookups[$buildName] ?? null;
            if ($lookup === null) {
                continue;
            }

            foreach ($lookup->getCssFiles($entryName) as $file) {
                $this->pageRenderer->addCssFile(
                    $file,
                    'stylesheet',
                    is_string($config['media'] ?? null) ? $config['media'] : 'all',
                    is_string($config['title'] ?? null) ? $config['title'] : '',
                    null,
                    (bool)($config['forceOnTop'] ?? false),
                    is_string($config['allWrap'] ?? null) ? $config['allWrap'] : '',
                    null,
                    is_string($config['splitChar'] ?? null) ? $config['splitChar'] : '|',
                    (bool)($config['inline'] ?? false),
                );
            }
        }
    }

    /**
     * @param array<string, EntrypointLookupInterface> $lookups
     */
    private function processJsIncludes(mixed $jsIncludes, array $lookups, bool $footer, bool $isLibrary): void
    {
        if (! is_array($jsIncludes)) {
            return;
        }

        foreach ($jsIncludes as $entryKey => $value) {
            if (! is_string($value) || ! str_starts_with($value, self::ENCORE_PREFIX)) {
                continue;
            }

            $config = $jsIncludes[$entryKey . '.'] ?? [];
            if (! is_array($config)) {
                $config = [];
            }

            [$buildName, $entryName] = $this->resolveBuildAndEntry($value);
            $lookup = $lookups[$buildName] ?? null;
            if ($lookup === null) {
                continue;
            }

            $type = is_string($config['type'] ?? null) ? $config['type'] : null;
            $forceOnTop = (bool)($config['forceOnTop'] ?? false);
            $allWrap = is_string($config['allWrap'] ?? null) ? $config['allWrap'] : '';
            $splitChar = is_string($config['splitChar'] ?? null) ? $config['splitChar'] : '|';
            $async = (bool)($config['async'] ?? false);
            $defer = (bool)($config['defer'] ?? false);

            foreach ($lookup->getJavaScriptFiles($entryName) as $file) {
                // PageRenderer keys jsLibs by name; multiple files in one entrypoint
                // (e.g. runtime.js + app.js) need distinct names or only the first wins.
                $libraryName = basename((string)$file);

                if ($footer && $isLibrary) {
                    $this->pageRenderer->addJsFooterLibrary(
                        $libraryName,
                        $file,
                        $type,
                        null,
                        $forceOnTop,
                        $allWrap,
                        false,
                        $splitChar,
                        $async,
                        '',
                        $defer,
                    );
                } elseif ($isLibrary) {
                    $this->pageRenderer->addJsLibrary(
                        $libraryName,
                        $file,
                        $type,
                        null,
                        $forceOnTop,
                        $allWrap,
                        false,
                        $splitChar,
                        $async,
                        '',
                        $defer,
                    );
                } elseif ($footer) {
                    $this->pageRenderer->addJsFooterFile(
                        $file,
                        $type,
                        null,
                        $forceOnTop,
                        $allWrap,
                        false,
                        $splitChar,
                        $async,
                        '',
                        $defer,
                    );
                } else {
                    $this->pageRenderer->addJsFile(
                        $file,
                        $type,
                        null,
                        $forceOnTop,
                        $allWrap,
                        false,
                        $splitChar,
                        $async,
                        '',
                        $defer,
                    );
                }
            }
        }
    }

    /**
     * @return array{0: string, 1: string} [buildName, entryName]
     */
    private function resolveBuildAndEntry(string $value): array
    {
        $stripped = substr($value, strlen(self::ENCORE_PREFIX));
        $parts = GeneralUtility::trimExplode(':', $stripped, true, 2);
        if (count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }
        return [EntrypointLookupInterface::DEFAULT_BUILD, $parts[0]];
    }
}
