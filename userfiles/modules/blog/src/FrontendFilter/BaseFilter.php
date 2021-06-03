<?php
namespace MicroweberPackages\Blog\FrontendFilter;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use MicroweberPackages\Blog\FrontendFilter\Traits\FiltersActiveTrait;
use MicroweberPackages\Blog\FrontendFilter\Traits\CategoriesTrait;
use MicroweberPackages\Blog\FrontendFilter\Traits\LimitTrait;
use MicroweberPackages\Blog\FrontendFilter\Traits\PaginationTrait;
use MicroweberPackages\Blog\FrontendFilter\Traits\SearchTrait;
use MicroweberPackages\Blog\FrontendFilter\Traits\SortTrait;
use MicroweberPackages\Blog\FrontendFilter\Traits\TagsTrait;
use MicroweberPackages\Category\Models\Category;
use MicroweberPackages\CustomField\Models\CustomField;
use MicroweberPackages\CustomField\Models\CustomFieldValue;
use MicroweberPackages\Page\Models\Page;

abstract class BaseFilter
{
    use CategoriesTrait, LimitTrait, PaginationTrait, SearchTrait, SortTrait, TagsTrait, FiltersActiveTrait;

    public $viewNamespace = false;

    public $allCustomFieldsForResults = [];
    public $allTagsForResults = [];

    public $filters = array();
    public $params = array();
    public $queryParams = array();
    protected $query;
    protected $model;
    protected $request;

    public function __construct()
    {
        $this->request = $this->_getRequestInstance();
    }

    public function setModel($model)
    {
        $this->model = $model;
    }

    public function setParams($params)
    {
        $this->params = $params;
    }

    public function setQuery($query)
    {
        $this->query = $query;
    }

    public function getMainPageId()
    {
        $contentFromId = get_option('content_from_id', $this->params['moduleId']);
        if ($contentFromId) {
            return $contentFromId;
        }

        $findFirtBlog = Page::where('content_type', 'page')
            ->where('subtype','dynamic')
            ->where('is_shop', 0)
            ->first();

        if ($findFirtBlog) {
            return $findFirtBlog->id;
        }

        return 0;
    }

    public function buildFilter()
    {
        $cacheTags = $this->model->getTable();
        $cacheId = 'buildFilter' . $this->getMainPageId() . $this->params['moduleId'];

        $checkCache =  Cache::tags($cacheTags)->get($cacheId);

        if (!empty($checkCache)) {
            if (isset($checkCache['allCustomFieldsForResults'])) {
                $this->allCustomFieldsForResults = $checkCache['allCustomFieldsForResults'];
            }
            if (isset($checkCache['allTagsForResults'])) {
                $this->allTagsForResults = $checkCache['allTagsForResults'];
            }
           return true;
        }

        $query = $this->model::query();
        $query->select(['id']);

        $query->with('tagged');
        $query->where('parent', $this->getMainPageId());

        $query->with('customField', function ($query) {
            $query->with('fieldValue',function ($query) {
                $query->whereNotNull('value');
                $query->groupBy('value');
            });
        });

        $results = $query->get();

        $allCustomFieldsForResults = [];

        if (!empty($results)) {
            foreach ($results as $result) {

                foreach($result->tags as $tag) {
                    $this->allTagsForResults[] = $tag;
                }

                $resultCustomFields = $result->customField;

                if (!empty($resultCustomFields)) {
                    foreach ($resultCustomFields as $resultCustomField) {

                        $customFieldOptionName = 'filtering_by_custom_fields_' . $resultCustomField->name_key;
                        if (get_option($customFieldOptionName, $this->params['moduleId']) != '1') {
                            continue;
                        }

                        $customFieldValuesClean = [];
                        $customFieldValues = $resultCustomField->fieldValue;
                        if (!empty($customFieldValues)) {
                            foreach($customFieldValues as $customFieldValue) {

                                $customFieldValueClean = new \stdClass();
                                $customFieldValueClean->id = $customFieldValue->id;
                                $customFieldValueClean->custom_field_id = $customFieldValue->custom_field_id;
                                $customFieldValueClean->value = $customFieldValue->value;

                                $customFieldValuesClean[] = $customFieldValueClean;
                            }
                        }

                        $resultCustomFieldClean = new \stdClass();
                        $resultCustomFieldClean->type = $resultCustomField->type;
                        $resultCustomFieldClean->name = $resultCustomField->name;
                        $resultCustomFieldClean->name_key = $resultCustomField->name_key;

                        if (!empty($customFieldValuesClean)) {
                            $allCustomFieldsForResults[$resultCustomField->id] = [
                                'customField'=>$resultCustomFieldClean,
                                'customFieldValues'=>$customFieldValuesClean,
                            ];
                        }
                    }
                }
            }
        }

        $this->allCustomFieldsForResults = $allCustomFieldsForResults;

        Cache::tags($cacheTags)->put($cacheId, [
            'allCustomFieldsForResults'=>$allCustomFieldsForResults,
            'allTagsForResults'=>$this->allTagsForResults,
        ]);
    }

    public function filters($template = 'blog::partials.filters')
    {
        $disableFilter = get_option('disable_filter', $this->params['moduleId']);
        if ($disableFilter) {
            return false;
        }

        $showPickedFirst = get_option('filtering_show_picked_first', $this->params['moduleId']);

        $requestFilters = $this->request->get('filters', false);

        $filters = [];

        if (!empty($this->allCustomFieldsForResults)) {
            $filterOptionsInactive = [];
            $filterOptionsActive = [];
            $customFieldsGrouped = [];
            foreach ($this->allCustomFieldsForResults as $result) {

                $customFieldsGrouped[$result['customField']->name_key] = $result['customField'];

                foreach ($result['customFieldValues'] as $customFieldValue) {

                    $customFieldValue->active = 0;

                    // Mark as active
                    if (!empty($requestFilters)) {
                        foreach ($requestFilters as $requestFilterKey => $requestFilterValues) {
                            if (is_array($requestFilterValues)) {
                                if ($requestFilterKey == $result['customField']->name_key) {
                                    foreach ($requestFilterValues as $requestFilterValue) {
                                        if ($requestFilterValue == $customFieldValue->value) {
                                            $customFieldValue->active = 1;
                                        }
                                    }
                                }
                            }
                        }
                    }

                    if ($showPickedFirst) {
                        if ($customFieldValue->active) {
                            $filterOptionsActive[$result['customField']->name_key][$customFieldValue->value] = $customFieldValue;
                        } else {
                            $filterOptionsInactive[$result['customField']->name_key][$customFieldValue->value] = $customFieldValue;
                        }
                    } else {
                        $filterOptions[$result['customField']->name_key][$customFieldValue->value] = $customFieldValue;
                    }
                }
            }

            if ($showPickedFirst) {
                // Order filters first active
                $filterOptions = $filterOptionsActive;
                foreach ($filterOptionsInactive as $customFieldNameKey => $filterOptionsValues) {
                    foreach ($filterOptionsValues as $filterOptionValueName => $filterOptionValue) {
                        $filterOptions[$customFieldNameKey][$filterOptionValueName] = $filterOptionValue;
                    }
                }
            }

            $i = 0;
            foreach ($customFieldsGrouped as $customFieldNameKey => $customField) {
                if (isset($filterOptions[$customFieldNameKey])) {

                    $readyFilterOptions = $filterOptions[$customFieldNameKey];

                    $controlType = get_option('filtering_by_custom_fields_control_type_' . $customFieldNameKey, $this->params['moduleId']);
                    if (empty($controlType)) {
                        $controlType = 'checkbox';
                    }

                    $filter = new \stdClass();
                    $filter->position = $i;
                    $filter->isFirst = ($i == 0 ? true : false);
                    $filter->type = $customField->type;
                    $filter->controlType = $controlType;
                    $filter->name = $customField->name;
                    $filter->nameKey = $customField->name_key;
                    $filter->options = $readyFilterOptions;

                    if ($filter->controlType == 'date_range') {
                        $filter->fromDate = '';
                        $filter->toDate = '';
                        if (isset($requestFilters['from_date'])) {
                            $filter->fromDate = $requestFilters['from_date'];
                        }
                        if (isset($requestFilters['to_date'])) {
                            $filter->toDate = $requestFilters['to_date'];
                        }
                    }

                    if ($filter->controlType == 'price') {

                        $allPrices = [];
                        foreach ($readyFilterOptions as $priceVal => $priceOption) {
                            $allPrices[] = $priceVal;
                        }

                        $minPrice = 0;
                        $maxPrice = 0;
                        if (isset($allPrices[0])) {
                            $sortedPrices = [];
                            asort($allPrices, SORT_STRING | SORT_FLAG_CASE | SORT_NATURAL);
                            foreach ($allPrices as $sortPrice) {
                                $sortedPrices[] = $sortPrice;
                            }
                            $minPrice = $sortedPrices[0];
                            $maxPrice = end($sortedPrices);
                        }

                        $filter->minPrice = round($minPrice);
                        $filter->maxPrice = round($maxPrice);

                        $filter->fromPrice = $filter->minPrice;
                        $filter->toPrice = $filter->maxPrice;

                        if ($this->request->get('min_price', false)) {
                            $filter->fromPrice = $this->request->get('min_price');
                        }

                        if ($this->request->get('max_price', false)) {
                            $filter->toPrice = $this->request->get('max_price');
                        }

                    }

                    $filters[$customFieldNameKey] = $filter;
                    $i++;
                }
            }

        }

        $readyOrderedFilters = [];
        $orderFiltersOption = get_option('filtering_by_custom_fields_order', $this->params['moduleId']);
        if (!empty($orderFiltersOption)) {
            $orderFilters = parse_query($orderFiltersOption);
            $i = 0;
            foreach ($orderFilters as $filter) {
                if (isset($filters[$filter])) {
                    $orderedFilter = $filters[$filter];
                    if ($i == 0) {
                        $orderedFilter->isFirst = true;
                    }
                    $orderedFilter->position = $i;
                    $i++;
                    $readyOrderedFilters[$filter] = $orderedFilter;
                }
            }
            $filters = $readyOrderedFilters;
        }

        $this->filters = $filters;

        $moduleId = $this->params['moduleId'];

        $viewData =  ['filters'=>$filters, 'moduleId'=>$moduleId];

        if (!$template) {
            return $viewData;
        }

        return view($template, $viewData);
    }

    public function apply()
    {
        $reflection = new \ReflectionClass(get_class($this));
        $traitMethods = $reflection->getMethods();
        foreach($traitMethods as $method) {
            // Apply query builds from traits
            if (strpos($method->name, 'applyQuery') !== false) {
                $this->{$method->name}();
            }
        }

        $this->query->where('parent', $this->getMainPageId());
        $this->query->select(['id','parent', 'url','title','content','content_body']);

        $filter = get_option('filtering_the_results', $this->params['moduleId']);
        if ($filter == '1') {
            $this->buildFilter();
        }

        $this->pagination = $this->query
            ->paginate($this->queryParams['limit'], ['*'], 'page', $this->request->get('page', 0))
            ->withQueryString();

        return $this;
    }

    public function scripts()
    {
        $modulesUrl = modules_url();

        $filtering = 'automatically';
        $filteringWhen = get_option('filtering_when', $this->params['moduleId']);
        if ($filteringWhen) {
            $filtering = $filteringWhen;
        }

        return '<script type="text/javascript">

            mw.require("'.$modulesUrl.'/blog/js/filter.js");
            mw.require("'.$modulesUrl.'/blog/css/filter.css");

            filter = new ContentFilter();
            filter.setModuleId("'.$this->params['moduleId'].'");
            filter.setFilteringWhen("'.$filtering.'");
            filter.init();
            </script>
        ';
    }

    private function _getRequestInstance()
    {
        $request = new \Illuminate\Http\Request($_REQUEST);

        $ajaxFilter = $request->get('ajax_filter');
        if (!empty($ajaxFilter)) {
            parse_str($ajaxFilter, $ajaxFilterDecoded);
            $request->merge($ajaxFilterDecoded);
        }

        return $request;
    }
}
