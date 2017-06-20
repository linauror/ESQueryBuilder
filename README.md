# ESQueryBulid

以QueryBulider的形式来调用elasticsearch的PHP类，只是针对查询及结果做了封装

如果你使用过任何PHP框架，那么很快就可以入门，比较像sql语句

本类暂时只在elasticsearch 5.4版本上进行了验证，其他版本未做验证

### 举几个例子：

#### 初始化

    include 'ESQueryBuilder.php';

    $ESQueryBuilder = new ESQueryBuilder('http://127.0.0.1:9200/test_index/test_type'); 
    // IP:端口/索引(库)/类型(表) 且支持多hosts数组形式
    
#### 单条查询
    
    $ESQueryBuilder->select(['id', 'name', 'sex', 'age'])->andWhere('id', '1')->getSingleResult();

##### 结果示例

    {
        id: 1,
        name: "test",
        sex: "1",
        age: "18"
    }
    
#### 列表查询
    
    $ESQueryBuilder->select(['id', 'name', 'sex', 'age'])->inWhere('sex', [1, 2])->limit(1, 10)->getLists();

##### 结果示例
    {
        "total": 100,
        "totalPages": 10,
        "pageSize": 10,
        "currentPage": 1,
        "lists": [
            {
                id: 1,
                name: "test",
                sex: "1",
                age: "18"
            }
        ]
    }
    
#### 聚合查询
    
    $ESQueryBuilder->sum('age')->groupBy('sex')->getAggs();

#### 获取原始查询语句

    $ESQueryBuilder->getLists(true);

##### 结果示例

    {
        "_source": [
            "id",
            "name",
            "age",
            "sex"
        ],
        "query": {
            "bool": {
                "must": [
                    {
                        "term": {
                            "id.keyword": {
                                "value": 1
                            }
                        }
                    },
                ],
            }
        },
        "sort": [
            {
                "id": {
                    "order": "desc"
                }
            }
        ],
        "size": 1,
        "from": 0
    }

#### 获取原始结果语句

    $ESQueryBuilder->getLists(false, true);

##### 结果示例

    {
        "took": 2,
        "timed_out": false,
        "_shards": {
            "total": 5,
            "successful": 5,
            "failed": 0
        },
        "hits": {
            "total": 139,
            "max_score": null,
            "hits": [
                {
                    "_index": "test_index",
                    "_type": "test_type",
                    "_id": "1",
                    "_score": null,
                    "_source": {
                        "id": "1",
                        "name": "test",
                        "sex": "1",
                        "age": "18"
                    }
                }
            ]
        }
    }
