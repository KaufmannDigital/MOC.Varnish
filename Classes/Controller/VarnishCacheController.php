<?php
namespace MOC\Varnish\Controller;

use MOC\Varnish\Service\ContentCacheFlusherService;
use MOC\Varnish\Service\VarnishBanService;
use Neos\Flow\Annotations as Flow;
use Neos\Error\Messages\Message;
use Neos\Flow\Http\Client\CurlEngine;
use Neos\Flow\Http\Uri;
use Neos\Flow\Http\Request;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;

class VarnishCacheController extends \Neos\Neos\Controller\Module\AbstractModuleController
{

    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Domain\Service\NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var \Neos\Neos\Domain\Service\ContentContextFactory
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var \Neos\Neos\Domain\Service\NodeSearchService
     */
    protected $nodeSearchService;

    /**
     * @Flow\Inject
     * @var \Neos\Neos\Domain\Repository\SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    /**
     * @var array
     */
    protected $viewFormatToObjectNameMap = array(
        'html' => 'Neos\FluidAdaptor\View\TemplateView',
        'json' => 'Neos\Flow\Mvc\View\JsonView'
    );

    /**
     * @return void
     */
    public function indexAction()
    {
        $this->view->assign('activeSites', $this->siteRepository->findOnline());
    }

    /**
     * @param string $searchWord
     * @param Site $selectedSite
     * @return void
     */
    public function searchForNodeAction($searchWord, Site $selectedSite = null)
    {
        $documentNodeTypes = $this->nodeTypeManager->getSubNodeTypes('Neos.Neos:Document');
        $shortcutNodeType = $this->nodeTypeManager->getNodeType('Neos.Neos:Shortcut');
        $nodeTypes = array_diff($documentNodeTypes, array($shortcutNodeType));
        $sites = array();
        $activeSites = $this->siteRepository->findOnline();
        foreach ($selectedSite ? array($selectedSite) : $activeSites as $site) {
            /** @var Site $site */
            $contextProperties = array(
                'workspaceName' => 'live',
                'currentSite' => $site
            );
            $contentDimensionPresets = $this->contentDimensionPresetSource->getAllPresets();
            if (count($contentDimensionPresets) > 0) {
                $mergedContentDimensions = array();
                foreach ($contentDimensionPresets as $contentDimensionIdentifier => $contentDimension) {
                    $mergedContentDimensions[$contentDimensionIdentifier] = array($contentDimension['default']);
                    foreach ($contentDimension['presets'] as $contentDimensionPreset) {
                        $mergedContentDimensions[$contentDimensionIdentifier] = array_merge($mergedContentDimensions[$contentDimensionIdentifier], $contentDimensionPreset['values']);
                    }
                    $mergedContentDimensions[$contentDimensionIdentifier] = array_values(array_unique($mergedContentDimensions[$contentDimensionIdentifier]));
                }
                $contextProperties['dimensions'] = $mergedContentDimensions;
            }
            /** @var ContentContext $liveContext */
            $liveContext = $this->contextFactory->create($contextProperties);
            $nodes = $this->nodeSearchService->findByProperties($searchWord, $nodeTypes, $liveContext, $liveContext->getCurrentSiteNode());
            if (count($nodes) > 0) {
                $sites[$site->getNodeName()] = array(
                    'site' => $site,
                    'nodes' => $nodes
                );
            }
        }
        $this->view->assignMultiple(array(
            'searchWord' => $searchWord,
            'selectedSite' => $selectedSite,
            'sites' => $sites,
            'activeSites' => $activeSites
        ));
    }

    /**
     * @param \Neos\ContentRepository\Domain\Model\Node $node
     * @return void
     */
    public function purgeCacheAction(\Neos\ContentRepository\Domain\Model\Node $node)
    {
        $service = new ContentCacheFlusherService();
        $service->flushForNode($node);
        $this->view->assign('value', true);
    }

    /**
     * @param string $tags
     * @param Site $site
     * @return void
     */
    public function purgeCacheByTagsAction($tags, Site $site = null)
    {
        $domains = null;
        if ($site !== null && $site->hasActiveDomains()) {
            $domains = $site->getActiveDomains()->map(function (Domain $domain) {
                return $domain->getHostname();
            })->toArray();
        }
        $tags = explode(',', $tags);
        $service = new VarnishBanService();
        $service->banByTags($tags, $domains);
        $this->flashMessageContainer->addMessage(new Message(sprintf('Varnish cache cleared for tags "%s" for %s', implode('", "', $tags), $site ? 'site ' . $site->getName() : 'installation')));
        $this->redirect('index');
    }

    /**
     * @param Site $site
     * @param string $contentType
     * @return void
     */
    public function purgeAllVarnishCacheAction(Site $site = null, $contentType = null)
    {
        $domains = null;
        if ($site !== null && $site->hasActiveDomains()) {
            $domains = $site->getActiveDomains()->map(function (Domain $domain) {
                return $domain->getHostname();
            })->toArray();
        }
        $service = new VarnishBanService();
        $service->banAll($domains, $contentType);
        $this->flashMessageContainer->addMessage(new Message(sprintf('All varnish cache cleared for %s%s', $site ? 'site ' . $site->getName() : 'installation', $contentType ? ' with content type "' . $contentType . '"' : '')));
        $this->redirect('index');
    }

    /**
     * @param string $url
     * @return string
     */
    public function checkUrlAction($url)
    {
        $uri = new Uri($url);
        if (isset($this->settings['reverseLookupPort'])) {
            $uri->setPort($this->settings['reverseLookupPort']);
        }
        $request = Request::create($uri);
        $request->setHeader('X-Cache-Debug', '1');
        $engine = new CurlEngine();
        $engine->setOption(CURLOPT_SSL_VERIFYPEER, false);
        $engine->setOption(CURLOPT_SSL_VERIFYHOST, false);
        $response = $engine->sendRequest($request);
        $this->view->assign('value', array(
            'statusCode' => $response->getStatusCode(),
            'host' => parse_url($url, PHP_URL_HOST),
            'url' => $url,
            'headers' => array_map(function ($value) {
                return array_pop($value);
            }, $response->getHeaders()->getAll())
        ));
    }
}
