<?php

namespace Fusonic\OpenGraph;

use Fusonic\Linq\Linq;
use Fusonic\OpenGraph\Objects\ObjectBase;
use Fusonic\OpenGraph\Objects\Website;
use GuzzleHttp\Adapter\AdapterInterface;
use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Consumer that extracts Open Graph data from either a URL or a HTML string.
 */
class Consumer
{
    private $client;

    /**
     * When enabled, crawler will read content of title and meta description if no
     * Open Graph data is provided by target page.
     *
     * @var bool
     */
    public $useFallbackMode = false;

    /**
     * When enabled, crawler will throw exceptions for some crawling errors like unexpected
     * Open Graph elements.
     *
     * @var bool
     */
    public $debug = false;

    /**
     * @param   AdapterInterface $adapter Guzzle adapter to use for making HTTP requests.
     * @param   array            $config  Optional Guzzle config overrides.
     */
    public function __construct(AdapterInterface $adapter = null, array $config = [])
    {
        $config = array_replace_recursive(['adapter' => $adapter], $config);

        $this->client = new Client($config);
    }

    /**
     * Fetches HTML content from the given URL and then crawls it for Open Graph data.
     *
     * @param   string  $url            URL to be crawled.
     *
     * @return  Website
     */
    public function loadUrl($url)
    {
        // Fetch HTTP content using Guzzle
        $response = $this->client->get($url);

        return $this->loadHtml($response->getBody()->__toString(), $url);
    }

    /**
     * Crawls the given HTML string for OpenGraph data.
     *
     * @param   string  $html           HTML string, usually whole content of crawled web resource.
     * @param   string  $fallbackUrl    URL to use when fallback mode is enabled.
     *
     * @return  ObjectBase
     */
    public function loadHtml($html, $fallbackUrl = null)
    {
        // Extract all data that can be found
        $page = $this->extractOpenGraphData($html);

        // Use the user's URL as fallback
        if ($this->useFallbackMode && $page->url === null) {
            $page->url = $fallbackUrl;
        }

        // Return result
        return $page;
    }

    private function extractOpenGraphData($content)
    {
        $crawler = new Crawler($content);

        $properties = [];
        foreach(['name', 'property'] as $t)
        {
            // Get all meta-tags starting with "og:"
            $ogMetaTags = $crawler->filter("meta[{$t}^='og:']");
            // Create clean property array
            $props = Linq::from($ogMetaTags)
                ->select(
                    function (\DOMElement $tag) use ($t) {
                        $name = strtolower(trim($tag->getAttribute($t)));
                        $value = trim($tag->getAttribute("content"));
                        return new Property($name, $value);
                    }
                )
                ->toArray();
            $properties = array_merge($properties, $props);

        }

        // Create new object of the correct type
        $typeProperty = Linq::from($properties)
            ->firstOrNull(
                function (Property $property) {
                    return $property->key === Property::TYPE;
                }
            );
        switch ($typeProperty !== null ? $typeProperty->value : null) {
            default:
                $object = new Website();
                break;
        }

        // Assign all properties to the object
        $object->assignProperties($properties, $this->debug);

        // Fallback for title
        if ($this->useFallbackMode && !$object->title) {
            $titleElement = $crawler->filter("title")->first();
            if ($titleElement && $titleElement->count()) {
                $object->title = trim($titleElement->text());
            }
        }

        // Fallback for description
        if ($this->useFallbackMode && !$object->description) {
            $descriptionElement = $crawler->filter("meta[property='description']")->first();
            if ($descriptionElement && $descriptionElement->count()) {
                $object->description = trim($descriptionElement->attr("content"));
            }
        }

        return $object;
    }
}
