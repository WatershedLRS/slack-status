<?php
$zone = new DateTimeZone('UCT');
    $customDateTo = new \DateTime('today midnight', $zone);
    $customDateFrom = clone $customDateTo;
    $customDateFrom->sub(new DateInterval('P29D'));
$params = [
        "name" => "response.csv",
        "requireCached" => false,
        "config" => json_encode((object)[
          "filter" => (object)[
            "dateFilter" => (object)[
              "dateType" => "custom",
              "customDateFrom" => $customDateFrom->format('c'),
              "customDateTo" => $customDateTo->format('c')
            ],
            "equals" => [(object)[
              "fieldName" => "actor.account.name",
              "values" => (object)[
                "ids" => [
                  "U08USRPB6"
                ],
                "regExp" => false
              ]
            ]]
          ],
          "dimensions" => [
            (object)[
              "type" => "STATEMENT_PROPERTY",
              "statementProperty" => "actor.person.id"
            ]
          ],
          "measures" => [
            (object)[
              "name" => "Days Status Update Posted",
              "aggregation" => (object)[
                "type" => "DISTINCT_COUNT"
              ],
              "valueProducer" => (object)[
                "type" => "TIME",
                "timePeriod" => "DAY"
              ],
              "filter" => (object)[
                "contextActivityIds" => (object)[
                  "ids" => [
                    "https://.slack.com/"
                  ]
                ],
                "verbIds" => (object)[
                  "ids" => [
                    "http://activitystrea.ms/schema/1.0/send"
                  ]
                ],
                "equals" => [
                  (object)[
                    "fieldName" => "object.definition.type",
                    "values" => (object)[
                      "ids" => [
                        "http://id.tincanapi.com/activitytype/chat-message"
                      ]
                    ]
                  ]
                ]
              ]
            ]
          ]
        ])
    ];

    $url .= '?'.http_build_query($params);

    var_dump($url);