<?php
namespace Neos\Neos\Http;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Http\ContentDimensionDetection\DimensionPresetDetectorResolver;
use Neos\Neos\Routing\ContentContextContainer;

/**
 * The HTTP component for detecting the requested dimension space point
 */
final class DetectContentSubgraphComponent implements Http\Component\ComponentInterface
{
    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $dimensionPresetSource;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var ContentContextContainer
     */
    protected $contentContextContainer;

    /**
     * @Flow\Inject
     * @var DimensionPresetDetectorResolver
     */
    protected $contentDimensionPresetDetectorResolver;

    /**
     * @Flow\InjectConfiguration(path="routing.supportEmptySegmentForDimensions")
     * @var boolean
     */
    protected $allowEmptyPathSegments;

    /**
     * @Flow\InjectConfiguration(path="contentDimensions.resolution.uriPathSegmentDelimiter")
     * @var string
     */
    protected $uriPathSegmentDelimiter;

    /**
     * @param Http\Component\ComponentContext $componentContext
     * @throws Exception\InvalidDimensionPresetDetectorException
     */
    public function handle(Http\Component\ComponentContext $componentContext)
    {
        $dimensionValues = $this->detectDimensionSpacePoint($componentContext);
        $workspaceName = $this->detectContentStream($componentContext);
        $contentContext = $this->buildContextFromWorkspaceNameAndDimensions($workspaceName, $dimensionValues);

        $componentContext->setParameter(
            DetectContentSubgraphComponent::class,
            'detectedContentContext',
            $contentContext
        );
        $this->contentContextContainer->setContentContext($componentContext->getParameter(DetectContentSubgraphComponent::class, 'detectedContentContext'));
        $this->contentContextContainer->setUriPathSegmentUsed($componentContext->getParameter(DetectContentSubgraphComponent::class, 'uriPathSegmentUsed') ?? false);
    }

    /**
     * Sets context properties like "invisibleContentShown" according to the workspace (live or not) and returns a
     * ContentContext object.
     *
     * @param string $workspaceName Name of the workspace to use in the context
     * @param array $dimensionsAndDimensionValues An array of dimension names (index) and their values (array of strings). See also: ContextFactory
     * @return ContentContext
     */
    protected function buildContextFromWorkspaceNameAndDimensions(string $workspaceName, array $dimensionsAndDimensionValues): ContentContext
    {
        $contextProperties = [
            'workspaceName' => $workspaceName,
            'invisibleContentShown' => ($workspaceName !== 'live'),
            'inaccessibleContentShown' => ($workspaceName !== 'live'),
            'dimensions' => $dimensionsAndDimensionValues
        ];

        /** @var ContentContext $context */
        $context = $this->contextFactory->create($contextProperties);

        return $context;
    }

    /**
     * @param Http\Component\ComponentContext $componentContext
     * @return array
     * @throws Exception\InvalidDimensionPresetDetectorException
     */
    protected function detectDimensionSpacePoint(Http\Component\ComponentContext $componentContext): array
    {
        $coordinates = [];
        $path = $componentContext->getHttpRequest()->getUri()->getPath();

        $isContextPath = NodePaths::isContextPath($path);
        $backendUriDimensionPresetDetector = new ContentDimensionDetection\BackendUriDimensionPresetDetector();
        $presets = $this->dimensionPresetSource->getAllPresets();
        $this->sortPresetsByOffset($presets);
        $uriPathSegmentOffset = 0;
        foreach ($presets as $dimensionName => $presetConfiguration) {
            $detector = $this->contentDimensionPresetDetectorResolver->resolveDimensionPresetDetector($dimensionName, $presetConfiguration);

            $options = $presetConfiguration['resolution']['options'] ?? $this->generateOptionsFromLegacyConfiguration($presetConfiguration, $uriPathSegmentOffset);

            if ($isContextPath) {
                $preset = $backendUriDimensionPresetDetector->detectPreset($dimensionName, $presetConfiguration['presets'], $componentContext);
                if ($preset) {
                    $coordinates[$dimensionName] = $preset['values'];
                    if ($detector instanceof ContentDimensionDetection\UriPathSegmentDimensionPresetDetector) {
                        // we might have to remove the uri path segment anyway
                        $uriPathSegmentPreset = $detector->detectPreset($dimensionName, $presetConfiguration['presets'], $componentContext, $options);
                        if ($uriPathSegmentPreset) {
                            $this->flagUriPathSegmentUsed($componentContext);
                        }
                    }
                    continue;
                }
            }

            $resolutionMode = $presetConfiguration['resolution']['mode'] ?? ContentDimensionResolutionMode::RESOLUTION_MODE_URIPATHSEGMENT;
            if ($resolutionMode === ContentDimensionResolutionMode::RESOLUTION_MODE_URIPATHSEGMENT) {
                $options['delimiter'] = $this->uriPathSegmentDelimiter;
            }
            $preset = $detector->detectPreset($dimensionName, $presetConfiguration['presets'], $componentContext, $options);
            if ($preset && $resolutionMode === ContentDimensionResolutionMode::RESOLUTION_MODE_URIPATHSEGMENT) {
                $this->flagUriPathSegmentUsed($componentContext);
                $uriPathSegmentOffset++;
            }
            if (!$preset && $options && isset($options['allowEmptyValue']) && $options['allowEmptyValue']) {
                if (isset($options['defaultPresetIdentifier']) && $options['defaultPresetIdentifier'] && isset($presetConfiguration['presets'][$options['defaultPresetIdentifier']])) {
                    $preset = $presetConfiguration['presets'][$options['defaultPresetIdentifier']];
                }
            }
            if ($preset) {
                $coordinates[$dimensionName] = $preset['values'];
            }
        }

        return $coordinates;
    }

    /**
     * @param array $presets
     * @return void
     */
    protected function sortPresetsByOffset(array & $presets)
    {
        uasort($presets, function ($presetA, $presetB) use ($presets) {
            if (isset($presetA['resolution']['options']['offset'])
                && isset($presetB['resolution']['options']['offset'])) {
                return $presetA['resolution']['options']['offset'] <=> $presetB['resolution']['options']['offset'];
            }

            return 0;
        });
    }

    /**
     * @param Http\Component\ComponentContext $componentContext
     * @return void
     */
    protected function flagUriPathSegmentUsed(Http\Component\ComponentContext $componentContext)
    {
        $componentContext->setParameter(
            DetectContentSubgraphComponent::class,
            'uriPathSegmentUsed',
            true
        );
    }

    /**
     * @todo remove once legacy configuration is removed (probably with 4.0)
     * @param array $presetConfiguration
     * @param int $uriPathSegmentOffset
     * @return array|null
     */
    protected function generateOptionsFromLegacyConfiguration(array $presetConfiguration, int $uriPathSegmentOffset)
    {
        $options = null;

        $resolutionMode = $presetConfiguration['resolution']['mode'] ?? ContentDimensionResolutionMode::RESOLUTION_MODE_URIPATHSEGMENT;
        if ($resolutionMode === ContentDimensionResolutionMode::RESOLUTION_MODE_URIPATHSEGMENT) {
            $options = [];
            if (!isset($options['offset'])) {
                $options['offset'] = $uriPathSegmentOffset;
            }
            if ($this->allowEmptyPathSegments) {
                $options['allowEmptyValue'] = true;
                $options['defaultPresetIdentifier'] = $presetConfiguration['defaultPreset'];
            } else {
                $options['allowEmptyValue'] = false;
                $options['defaultPresetIdentifier'] = null;
            }
        }

        return $options;
    }

    /**
     * @param Http\Component\ComponentContext $componentContext
     * @return string
     */
    protected function detectContentStream(Http\Component\ComponentContext $componentContext): string
    {
        $contentStreamIdentifier = 'live';

        $requestPath = $componentContext->getHttpRequest()->getUri()->getPath();
        if ($requestPath !== '' && NodePaths::isContextPath($requestPath)) {
            try {
                $nodePathAndContext = NodePaths::explodeContextPath($requestPath);
                $contentStreamIdentifier = $nodePathAndContext['workspaceName'];
            } catch (\InvalidArgumentException $exception) {
            }
        }

        return $contentStreamIdentifier;
    }
}