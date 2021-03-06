<?php

/**
 * 以QueryBulider的形式来使用ES，并对结果进行封装返回（如果错误直接返回ES报错信息）
 * 如果你使用过任何PHP框架，那么很快就可以入门
 * 本类暂时只在ES5.4版本上进行了验证，其他版本未做验证。
 *
 * 举几个例子：
 *
 * 初始化
 *
 * include 'ESQueryBuilder.php';
 * $ESQueryBuilder = new ESQueryBuilder('http://127.0.0.1:9200/test_index/test_type'); IP:端口/索引(库)/类型(表) 且支持多hosts数组形式
 *
 * 单条查询
 *
 * $ESQueryBuilder->select(['id', 'name', 'sex', 'age'])->andWhere('id', '100001')->getSingleResult();
 *
 * 列表查询
 *
 * $ESQueryBuilder->select(['id', 'name', 'sex', 'age'])->inWhere('sex', [1, 2])->limit(1, 10)->getLists();
 *
 * 聚合查询
 *
 * $ESQueryBuilder->sum('age')->groupBy('sex')->getAggs();
 *
 * Class ESQueryBuilder
 * Author linauror
 * Date 2017-06-13
 */
class ESQueryBuilder
{
    private $baseUrl;
    private $queryArr;
    private $aggs;
    private $pageSize = 10;
    private $page = 1;
    private $index;
    private $type;
    private $mapCacheDir; // mapping缓存路径
    private $mapping;

    public function __construct($hosts)
    {
        // 如果host有多个，随机取一个
        if (is_array($hosts)) {
            $hosts = $hosts[array_rand($hosts)];
        }
        $_host = explode('/', $hosts);
        $this->index = $_host[3];
        $this->type = $_host[4];
        $this->baseUrl = $hosts;
        $this->mapCacheDir = './';
        $this->mapCacheInit();
    }

    /**
     * mapping初始化
     */
    private function mapCacheInit()
    {
        if (! file_exists($this->mapCacheDir)) {
            exit('none exist mapCacheDir');
        }
        $cacheFile = $this->mapCacheDir . 'es_mapping_cache_' . $this->index . '_' . $this->type . '.php';
        if (! file_exists($cacheFile)) {
            $this->mapping = $this->getMapping();
            file_put_contents($cacheFile, '<?php return \'' . json_encode($this->mapping) . '\';');
        } else {
            $this->mapping = json_decode(include $cacheFile, true);
        }
    }

    /**
     * 判断字段是否为关键字
     * @param $field
     * @return string
     */
    private function isKeyword($field)
    {
        if (!in_array($field, array_keys($this->mapping))) {
            return $field;
        } else {
            return $field . ($this->mapping[$field]['keyword'] ? '.keyword' : '');
        }
    }

    /**
     * 选取字段，字段可用逗号分割
     * @param string /array $fileds
     * @return object $this
     */
    public function select($fileds)
    {
        !is_array($fileds) && $fileds = explode(',', $fileds);
        $this->queryArr['_source'] = $fileds;
        return $this;
    }

    /**
     * 排序
     * @param string $field
     * @param        $sort_flag desc/asc
     * @return object
     */
    public function orderBy($field, $sort_flag)
    {
        $this->queryArr['sort'][] = [$this->isKeyword($field) => ['order' => $sort_flag]];
        return $this;
    }

    /**
     * AND条件，支持数组
     * @param        $field
     * @param string $value
     * @return $this
     */
    public function andWhere($field, $value = '')
    {
        if (is_array($field)) {
            foreach ($field as $k => $v) {
                $this->queryArr['query']['bool']['must'][]['term'] = [$this->isKeyword($k) => $v];
            }
        } else {
            $this->queryArr['query']['bool']['must'][]['term'] = [$this->isKeyword($field) => $value];
        }

        return $this;
    }

    /**
     * LIKE条件，支持数组
     * @param        $field
     * @param string $value
     * @return $this
     */
    public function likeWhere($field, $value = '')
    {
        if (is_array($field)) {
            foreach ($field as $k => $v) {
                $v = str_replace('%', '*', $v);
                $this->queryArr['query']['bool']['must'][]['wildcard'] = [$this->isKeyword($k) => ['value' => $v]];
            }
        } else {
            $value = str_replace('%', '*', $value);
            $this->queryArr['query']['bool']['must'][]['wildcard'] = [$this->isKeyword($field) => ['value' => $value]];
        }

        return $this;
    }


    /**
     * 不等于条件，支持数组
     * @param        $field
     * @param string $value
     * @return $this
     */
    public function notWhere($field, $value = '')
    {
        if (is_array($field)) {
            foreach ($field as $k => $v) {
                $this->queryArr['query']['bool']['must_not'][]['term'] = [$this->isKeyword($k) => $v];
            }
        } else {
            $this->queryArr['query']['bool']['must_not'][]['term'] = [$this->isKeyword($field) => $value];
        }

        return $this;
    }

    /**
     * IN条件
     * @param      $field
     * @param      $values
     * @return $this
     */
    public function inWhere($field, $values)
    {
        $this->queryArr['query']['bool']['must'][]['terms'] = [$this->isKeyword($field) => $values];
        return $this;
    }

    /**
     * NOTIN条件
     * @param      $field
     * @param      $values
     * @return $this
     */
    public function notInWhere($field, $values)
    {
        $this->queryArr['query']['bool']['must_not'][]['terms'] = [$this->isKeyword($field) => $values];
        return $this;
    }

    /**
     * 范围条件
     * @param      $field
     * @param      $min
     * @param      $max
     * @return $this
     */
    public function betweenWhere($field, $min, $max)
    {
        $this->queryArr['query']['bool']['must'][]['range'] = [$this->isKeyword($field) => ['gte' => $min, 'lte' => $max]];
        return $this;
    }

    /**
     * 结果条数
     * @param int $page
     * @param int $pageSize
     * @return $this
     */
    public function limit($page = 1, $pageSize = 10)
    {
        $this->page = $page;
        $this->pageSize = $pageSize;
        $this->queryArr['size'] = $pageSize;
        $this->queryArr['from'] = ($page - 1) * $pageSize;
        return $this;
    }

    /**
     * 总和，支持数组
     * @param        string /array      $field 字段
     * @param string $alias 别名，不填则为字段名
     * @return $this
     */
    public function sum($field, $alias = '')
    {
        $field = is_string($field) && strpos($field, ',') !== false ? explode(',', $field) : $field;
        if (is_array($field)) {
            foreach ($field as $k => $v) {
                $k = is_numeric($k) ? $v : $k;
                $this->aggs[$k]['sum']['field'] = $v;
            }
        } else {
            $alias = $alias ? $alias : $field;
            $this->aggs[$alias]['sum']['field'] = $field;
        }

        return $this;
    }

    /**
     * 计数
     * @param        string /array  $field 字段
     * @param string $alias 别名，不填则为字段名
     * @return $this
     */
    public function count($field, $alias = '')
    {
        $field = is_string($field) && strpos($field, ',') !== false ? explode(',', $field) : $field;
        if (is_array($field)) {
            foreach ($field as $k => $v) {
                $k = is_numeric($k) ? $v : $k;
                $this->aggs[$k]['value_count']['field'] = $v;
            }
        } else {
            $alias = $alias ? $alias : $field;
            $this->aggs[$alias]['value_count']['field'] = $field;
        }

        return $this;
    }

    /**
     * 平均值，支持数组
     * @param        $field 字段
     * @param string $alias 别名，不填则为字段名
     * @return $this
     */
    public function avg($field, $alias = '')
    {
        $field = is_string($field) && strpos($field, ',') !== false ? explode(',', $field) : $field;
        if (is_array($field)) {
            foreach ($field as $k => $v) {
                $k = is_numeric($k) ? $v : $k;
                $this->aggs[$k]['avg']['field'] = $v;
            }
        } else {
            $alias = $alias ? $alias : $field;
            $this->aggs[$alias]['avg']['field'] = $field;
        }
        return $this;
    }

    /**
     * 最大值
     * @param        $field 字段
     * @param string $alias 别名，不填则为字段名
     * @return $this
     */
    public function max($field, $alias = '')
    {
        $field = is_string($field) && strpos($field, ',') !== false ? explode(',', $field) : $field;
        if (is_array($field)) {
            foreach ($field as $k => $v) {
                $k = is_numeric($k) ? $v : $k;
                $this->aggs[$k]['max']['field'] = $v;
            }
        } else {
            $alias = $alias ? $alias : $field;
            $this->aggs[$alias]['max']['field'] = $field;
        }
        return $this;
    }

    /**
     * 最小值
     * @param        string /array $field 字段
     * @param string $alias 别名，不填则为字段名
     * @return $this
     */
    public function min($field, $alias = '')
    {
        $field = is_string($field) && strpos($field, ',') !== false ? explode(',', $field) : $field;
        if (is_array($field)) {
            foreach ($field as $k => $v) {
                $k = is_numeric($k) ? $v : $k;
                $this->aggs[$v]['min']['field'] = $v;
            }
        } else {
            $alias = $alias ? $alias : $field;
            $this->aggs[$alias]['min']['field'] = $field;
        }
        return $this;
    }

    /**
     * 分组
     * @param $field
     * @return $this
     */
    public function groupBy($field)
    {
        if (!isset($this->queryArr['_source']) || !in_array($field, $this->queryArr['_source'])) {
            $this->queryArr['_source'][] = $field;
        }
        return $this;
    }

    /**
     * 获取列表结果
     * @param bool $getQuery
     * @param bool $rawResult
     * @return array|mixed
     */
    public function getLists($getQuery = false, $rawResult = false)
    {
        if ($getQuery) {
            return $this->queryArr;
        }
        $result = $this->execute();
        if ($rawResult || isset($result['error'])) {
            return $result;
        }

        $totalPages = ceil($result['hits']['total'] / $this->pageSize);
        $_result = ['total' => $result['hits']['total'], 'totalPages' => $totalPages, 'pageSize' => $this->pageSize, 'currentPage' => $this->page, 'lists' => []];
        if ($_result['total']) {
            foreach ($result['hits']['hits'] as $line) {
                $_result['lists'][] = $line['_source'];
            }
        }

        return $_result;
    }

    /**
     * 获取单条结果
     * @param bool $getQuery
     * @param bool $rawResult
     * @return array|mixed
     */
    public function getSingleResult($getQuery = false, $rawResult = false)
    {
        $this->limit(1, 1);
        if ($getQuery) {
            return $this->queryArr;
        }
        $result = $this->execute();
        if ($rawResult || isset($result['error'])) {
            return $result;
        }
        if ($result['hits']['total'] == 0) {
            return [];
        }
        return $result['hits']['hits'][0]['_source'];
    }

    /**
     * 获取聚合结果
     * @param bool $getQuery 返回原始请求
     * @param bool $rawResult 返回原始结果
     * @return array|mixed
     */
    public function getAggs($getQuery = false, $rawResult = false)
    {
        $this->limit(1, 0);
        $query = [];
        if ($this->aggs) {
            $query = ['aggs' => $this->aggs];
        }
        if (isset($this->queryArr['_source'])) {
            $select = $this->queryArr['_source'];
            $_select = array_reverse($select);
            foreach($_select as $v) {
                // size=10000000为了解决聚合分页的问题，sum_other_doc_count有值代表有未统计到的
                $query = ['aggs' => [$v => ['terms' => ['field' => $this->isKeyword($v), 'size' => 10000000]] + $query]];
            }
        }
        $this->queryArr += $query;

        if ($getQuery) {
            return $this->queryArr;
        }
        $result = $this->execute();
        if ($rawResult || isset($result['error'])) {
            return $result;
        }

        $_result = [];
        if (!$result['hits']['total']) {
            return $_result;
        }

        if (isset($select)) {
            $_result = $this->aggsDecode($result['aggregations'], $select);
        } else {
            foreach ($result['aggregations'] as $k => $v) {
                $_result[$k] = $v['value'];
            }
        }

        return $_result;
    }

    /**
     * 解析聚合数据
     * @param array $aggs
     * @param array $select
     * @param array $addon
     * @param array $result
     * @return array|mixed
     */
    public function aggsDecode($aggs = [], $select = [], $addon = [], &$result = [])
    {
        if ($select) {
            $key = current($select);
            $aggs = $aggs[$key]['buckets'];
            foreach($aggs as $v) {
                $addon[$key] = $v['key'];
                $_select = $select;
                array_shift($_select);
                $this->aggsDecode($v, $_select, $addon, $result);
            }
        } else {
            $_last = [];
            foreach($aggs as $k => $v) {
                if (is_array($v)) {
                    $_last[$k] = $v['value']; 
                }
            }

            $last = array_merge($addon, $_last);
            $result[] = $last;
        }

        return $result;
    }

    /**
     * 获取文档结构
     * @return mixed
     */
    public function getMapping()
    {
        $result = $this->doRequest($this->baseUrl . '/_mapping');
        $result = json_decode($result, true);
        $mapping = [];
        foreach($result[$this->index]['mappings'][$this->type]['properties'] as $k => $v) {
            if (!in_array($k, ['aggs', 'query'])) {
                $mapping[$k] = ['type' => $v['type'], 'keyword' => (isset($v['fields']['keyword']) ? 1 : 0)];
            }
        }
        return $mapping;
    }

    /**
     * 获取结果
     * @return mixed
     */
    public function execute()
    {
        $res = $this->doRequest($this->baseUrl . '/_search', json_encode($this->queryArr));
        $this->queryArr = [];
        $this->aggs = [];
        $result = json_decode($res, true);
        return $result;
    }

    /**
     * curl请求类
     * @param        $url
     * @param array  $params
     * @param string $method
     * @return mixed
     */
    public function doRequest($url, $params = [], $method = 'POST')
    {
        if (!function_exists('curl_init')) {
            exit('Need to open the curl extension');
        }
        if ($method == 'GET' && $params && is_array($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, ($url));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        curl_setopt($ch, CURLOPT_HEADER, 0); //展示响应头
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);//设置连接等待时间,0不等待
        if ($method == 'POST' && $params) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        }

        $output = curl_exec($ch);
        curl_close($ch);

        return $output;
    }
}
