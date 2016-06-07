<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\RouterBundle\Routing;

use ONGR\ElasticsearchBundle\Mapping\MetadataCollector;
use ONGR\ElasticsearchBundle\Result\Result;
use ONGR\ElasticsearchBundle\Service\Manager;
use ONGR\ElasticsearchDSL\Query\MatchQuery;
use ONGR\ElasticsearchDSL\Query\BoolQuery;
use ONGR\ElasticsearchDSL\Query\NestedQuery;
use ONGR\ElasticsearchDSL\Search;
use Symfony\Cmf\Component\Routing\RouteProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class ElasticsearchRouteProvider implements RouteProviderInterface
{
    /**
     * @var array Route map configuration to map Elasticsearch types and Controllers.
     */
    private $routeMap;

    /**
     * @var Manager
     */
    private $manager;

    /**
     * @var MetadataCollector
     */
    private $collector;

    /**
     * ElasticsearchRouteProvider constructor.
     *
     * @param MetadataCollector $collector
     * @param array $routeMap
     */
    public function __construct($collector, array $routeMap = [])
    {
        $this->collector = $collector;
        $this->routeMap = $routeMap;
    }

    /**
     * Returns Elasticsearch manager instance that was set in app/config.yml.
     *
     * @return Manager
     */
    public function getManager()
    {
        return $this->manager;
    }

    /**
     * @param Manager $manager
     */
    public function setManager(Manager $manager)
    {
        $this->manager = $manager;
    }
    /**
     * @return array
     */
    public function getRouteMap()
    {
        return $this->routeMap;
    }

    /**
     * {@inheritdoc}
     */
    public function getRouteCollectionForRequest(Request $request)
    {
        if (!$this->manager) {
            throw new ParameterNotFoundException(
                'Manager must be set to execute query to the elasticsearch'
            );
        }

        $routeCollection = new RouteCollection();
        $requestPath = $request->getPathInfo();

        //echo $requestPath; exit;

        $search = new Search();
        //$search->addFilter(new MatchQuery('url', $requestPath));

//        $results = $this->manager->execute(array_keys($this->routeMap), $search, Result::RESULTS_OBJECT);


        // CHANGED FOR VARIANTS

        $boolMain = new BoolQuery();
        $boolMain->addParameter("minimum_should_match", 1);

        $boolVariants = new BoolQuery();
        $boolVariants->addParameter("minimum_should_match", 1);

        $boolProduct = new BoolQuery();
        $boolProduct->addParameter("minimum_should_match", 1);



        $productQuery = new MatchQuery('url', $requestPath);
        $variantsQuery = new MatchQuery('variants.url', $requestPath);

        $boolVariants->add( $variantsQuery, BoolQuery::MUST);

        $nestedQuery = new NestedQuery(
            'variants',
           $boolVariants
        );


        $boolProduct->add($productQuery, BoolQuery::MUST);

        $boolMain->add($boolProduct, BoolQuery::SHOULD);
        $boolMain->add($nestedQuery, BoolQuery::SHOULD);


        $search->addQuery($boolMain);


        $results = $this->manager->execute(array_keys($this->routeMap), $search, Result::RESULTS_OBJECT);


        // END BLAAA



       //print_r($results); exit;

        try {
            foreach ($results as $document) {
                $type = $this->collector->getDocumentType(get_class($document));
                if (isset($this->routeMap[$type])) {
                    $route = new Route(
                        $document->getUrl(),
                        [
                            '_controller' => $this->routeMap[$type],
                            'document' => $document,
                            'type' => $type,
                        ]
                    );
                    $routeCollection->add('ongr_route_' . $route->getDefault('type'), $route);

                    if(count($document->variants) > 0)
                    {
                        foreach ($document->variants as $variant)
                        {
                            if($variant->getUrl() == $requestPath)
                            {
                                $route = new Route(
                                    $variant->getUrl(),
                                    [
                                        '_controller' => $this->routeMap[$type],
                                        'document' => $document,
                                        'type' => $type,
                                    ]
                                );

                                $routeCollection->add('ongr_route_' . $route->getDefault('type'), $route);
                            }
                        }
                    }

                } else {
                    throw new RouteNotFoundException(sprintf('Route for type %s% cannot be generated.', $type));
                }
            }
        } catch (\Exception $e) {
            //print_r($e); exit;
            throw new RouteNotFoundException('Document is not correct or route cannot be generated.');
        }
        //print_r($routeCollection); exit;
        return $routeCollection;
    }

    /**
     * {@inheritdoc}
     */
    public function getRouteByName($name)
    {
        throw new RouteNotFoundException('Dynamic provider generates routes on the fly.');
    }

    /**
     * {@inheritdoc}
     */
    public function getRoutesByNames($names)
    {
        // Returns empty Route collection.
        return new RouteCollection();
    }
}
