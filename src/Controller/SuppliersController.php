<?php

namespace App\Controller;

use Psr\SimpleCache\CacheInterface;
use Studio24\Frontend\ContentModel\ContentModel;
use Studio24\Frontend\Exception\PaginationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Studio24\Frontend\Cms\RestData;
use Symfony\Component\HttpFoundation\Request;
use Studio24\Frontend\Exception\NotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Exception;

class SuppliersController extends AbstractController
{

    /**
     * Suppliers Rest API data
     *
     * @var RestData
     */
    protected $api;

    /**
     * Suppliers Search Rest API data
     *
     * @var RestData
     */
    protected $searchApi;

    public function __construct(CacheInterface $cache)
    {
        $this->api = new RestData(
            getenv('APP_API_BASE_URL'),
            new ContentModel(__DIR__ . '/../../config/content/content-model.yaml')
        );
        $this->api->setContentType('suppliers');
        $this->api->setCache($cache);
        $this->api->setCacheLifetime(900);

        $this->searchApi = new RestData(
            getenv('SEARCH_API_BASE_URL'),
            new ContentModel(__DIR__ . '/../../config/content/content-model.yaml')
        );

        $this->searchApi->setContentType('suppliers');
        $this->searchApi->setCache($cache);
        $this->searchApi->setCacheLifetime(1);
    }


    /**
     * List active suppliers
     *
     * @param int $page
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Studio24\Frontend\Exception\ContentFieldException
     * @throws \Studio24\Frontend\Exception\ContentTypeNotSetException
     * @throws \Studio24\Frontend\Exception\FailedRequestException
     * @throws \Studio24\Frontend\Exception\PaginationException
     * @throws \Studio24\Frontend\Exception\PermissionException
     */
    public function list(Request $request, int $page = 1)
    {
        $page = filter_var($page, FILTER_SANITIZE_NUMBER_INT);

        $this->searchApi->setCacheKey($request->getRequestUri());

        // We are overriding the content model here
        $this->searchApi->getContentType()->setApiEndpoint('suppliers');

        try {
            $results = $this->searchApi->list($page);
        } catch (Exception $e) {
             // refresh page on 500 error
             return $this->redirect($request->getUri());
        }

        $limit = $request->query->has('limit') ? (int)filter_var(
            $request->query->get('limit'),
            FILTER_SANITIZE_NUMBER_INT
        ) : 20;
        $framework = $request->query->has('framework') ? filter_var(
            $request->query->get('framework'),
            FILTER_SANITIZE_STRING
        ) : null;

        $results->getPagination()->setResultsPerPage($limit);

        $facets = $results->getMetadata()->offsetGet('facets');

        $data = [
          'page_number'         => $page,
          'search_api_base_url' => getenv('SEARCH_API_BASE_URL'),
          'query'      => '',
          'limit'      => $limit,
          'pagination' => $results->getPagination(),
          'results'    => $results,
          'facets'     => $facets,
          'selected'   => [
              'framework' => $framework,
              'lot'       => '',
              'rm_number' => '',
              'lot_id'    => ''
          ]
        ];

        return $this->render('suppliers/list.html.twig', $data);
    }

    /**
     * Search suppliers
     *
     * @see http://ccs-agreements.cabinetoffice.localhost/wp-json/ccs/v1/suppliers/?keyword=503645152
     * @see http://ccs-agreements.cabinetoffice.localhost/wp-json/ccs/v1/suppliers/?keyword=RM6073
     * @see http://ccs-agreements.cabinetoffice.localhost/wp-json/ccs/v1/suppliers/?keyword=courier
     *
     * @param Request $request
     * @param int $page
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Studio24\Frontend\Exception\ContentFieldException
     * @throws \Studio24\Frontend\Exception\ContentTypeNotSetException
     * @throws \Studio24\Frontend\Exception\FailedRequestException
     * @throws \Studio24\Frontend\Exception\PaginationException
     * @throws \Studio24\Frontend\Exception\PermissionException
     */
    public function search(Request $request, int $page = 1)
    {
        // Get search query
        $query = filter_var($request->query->get('q'), FILTER_SANITIZE_STRING);
        $page = filter_var($page, FILTER_SANITIZE_NUMBER_INT);

        // Type param
        $type = filter_var($request->query->get('t'), FILTER_SANITIZE_STRING);
        if (!empty($type) && $type == 'old') {
            /**
             * Replace dash separated with spaces (+)
             * From: q=bramble-hub-limited
             * To:   q=bramble+hub+limited
             */
            $query = str_replace('-', '+', $query);
        }

        $this->searchApi->setCacheKey($request->getRequestUri());

        // We are overriding the content model here
        $this->searchApi->getContentType()->setApiEndpoint('suppliers');

        $limit = $request->query->has('limit') ? (int) filter_var($request->query->get('limit'), FILTER_SANITIZE_NUMBER_INT) : 20;
        $frameworkId = $request->query->has('framework') ? filter_var($request->query->get('framework'), FILTER_SANITIZE_STRING) : null;
        $lotId = $request->query->has('lot-filter-nested') ? filter_var($request->query->get('lot-filter-nested'), FILTER_SANITIZE_STRING) : null;
        if ($lotId == 'all' || $lotId == 'filterLot') {
            $lotId = null;
        }

        try {
            $results = $this->searchApi->list($page, [
                'keyword'   => $query,
                'limit'     => $limit,
                'framework' => $frameworkId,
                'lot'       => $lotId
            ]);
        } catch (Exception $e) {
             // refresh page on 500 error
             return $this->redirect($request->getUri());
        }

        $facets = $results->getMetadata()->offsetGet('facets');

        $lotObject = $this->retrieveLotFromFacetsUsingLotId($lotId, $facets);
        $frameworkObject = $this->retrieveFrameworkFromFacetsUsingLotId($frameworkId, $facets);

        $data = [
            'page_number'         => $page,
            'search_api_base_url' => getenv('SEARCH_API_BASE_URL'),
            'query'               => (!empty($query) ? $query : ''),
            'pagination'          => $results->getPagination(),
            'results'             => $results,
            'facets'              => $facets,
            'limit'               => $limit,
            'selected' => [
                'framework' => $frameworkObject,
                'rm_number' => $frameworkId,
                'lot'       => $lotObject,
                'lot_id'    => $lotId
            ]
        ];

        return $this->render('suppliers/list.html.twig', $data);
    }

    /**
     * Show supplier detail page
     *
     * @param string $id
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Studio24\Frontend\Exception\ContentFieldException
     * @throws \Studio24\Frontend\Exception\ContentFieldNotSetException
     * @throws \Studio24\Frontend\Exception\ContentTypeNotSetException
     * @throws \Studio24\Frontend\Exception\FailedRequestException
     * @throws \Studio24\Frontend\Exception\PermissionException
     */
    public function show(string $id, Request $request)
    {
        $id = filter_var($id, FILTER_SANITIZE_NUMBER_INT);

        $this->api->setCacheKey($request->getRequestUri());

        try {
            $results = $this->api->getOne($id);
        } catch (NotFoundException $e) {
            throw new NotFoundHttpException('Supplier not found', $e);
        }

        $listOfGuarantor = $this->getListOfGuarantor($results);

        $data = [
            'supplier' => $results,
            'listOfGuarantor' => $listOfGuarantor
        ];
        return $this->render('suppliers/show.html.twig', $data);
    }


    /**
     * Attempt to retrieve the lot object for a lot from the facet data
     * searching by lot ID
     *
     * @param $lotId
     * @param $facets
     * @return |null
     */
    protected function retrieveFrameworkFromFacetsUsingLotId($frameworkRmNumber, $facets)
    {
        if (empty($frameworkRmNumber) || empty($facets) || !isset($facets['frameworks'])) {
            return null;
        }

        foreach ($facets['frameworks'] as $facetFramework) {
            if ($facetFramework['rm_number'] == $frameworkRmNumber) {
                return $facetFramework;
            }
        }

        return null;
    }


    /**
     * Attempt to retrieve the lot object for a lot from the facet data
     * searching by lot ID
     *
     * @param $lotId
     * @param $facets
     * @return |null
     */
    protected function retrieveLotFromFacetsUsingLotId($lotId, $facets)
    {
        if (empty($lotId) || empty($facets) || !isset($facets['lots'])) {
            return null;
        }

        foreach ($facets['lots'] as $facetLot) {
            if ($facetLot['id'] == $lotId) {
                return $facetLot;
            }
        }

        return null;
    }

    protected function getListOfGuarantor($resultsFromCmdEndpoint)
    {
        $ListOfguarantorName = [];

        $agreements = $resultsFromCmdEndpoint->getContent()['live_frameworks']->getValue();

        foreach ($agreements as $agreement) {
            $lots = $agreement['lots']->getValue();

            foreach ($lots as $lot) {
                if (array_key_exists('guarantor_name', $lot)) {
                    $ListOfguarantorName[] = $lot['guarantor_name']->getValue();
                }
            }
        }

        return $ListOfguarantorName;
    }
}
