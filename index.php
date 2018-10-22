<?php
require_once('config.php');
require_once("TinCanPHP/autoload.php");

date_default_timezone_set('UTC');

# Initial request checks
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die();
}

if (!isset($_POST["token"]) || !($_POST["token"] === $CFG->slack->token)) {
    http_response_code(401);
    die();
}

if (
    !isset($_POST["user_name"]) 
    || !isset($_POST["user_id"]) 
    || !isset($_POST["team_domain"]) 
    || !isset($_POST["channel_name"]) 
    || !isset($_POST["text"])
    || !isset($_POST["response_url"])
    || !isset($_POST["trigger_id"])
) {
    http_response_code(400);
    die();
}

# Send immediate OK response, and then process the rest async
ignore_user_abort(true);
set_time_limit(0);
ob_start();
header('Content-Type: application/json');
echo('{"response_type":"in_channel", "text":""}');
header('Connection: close');
header('Content-Length: '.ob_get_length());
ob_end_flush();
ob_flush();
flush();

# Post a statement of the status update
$lrs = new TinCan\RemoteLRS(
    $CFG->lrs->endpoint,
    '1.0.1',
    $CFG->lrs->key,
    $CFG->lrs->secret
);

$text = $_POST["text"];

$actor = new TinCan\Agent(
    [ 
        'name' => $_POST["user_name"],
        'account' => [
            'name' => $_POST["user_id"],
            'homePage' => 'https://'.$_POST["team_domain"].'.slack.com'
        ]
    ]
);
$verb = new TinCan\Verb(
    [ 
        'id' => 'http://activitystrea.ms/schema/1.0/send',
        'display' => [
            'en' => 'sent'
        ]
    ]
);
$activity = new TinCan\Activity(
    [
        'id' => 'https://'.$_POST["team_domain"].'.slack.com/archives/'.$_POST["channel_name"].'/p'.$_POST["trigger_id"],
        'definition' => [
            'name' => [
                'en' => $text
            ],
            'type' => 'http://id.tincanapi.com/activitytype/chat-message'
        ]
    ]
);
$result  = new TinCan\Result(
    [
        "response" => $text
    ]
);
$context  = new TinCan\Context(
    [
        "contextActivities" => [
            "parent" => [
                [
                    "id" => "https://".$_POST["team_domain"].".slack.com/messages/".$_POST["channel_name"],
                    "definition" => [
                        "name" => [
                            "en" => $_POST["channel_name"]
                        ],
                        "type" => "http://id.tincanapi.com/activitytype/chat-channel"
                    ],
                    "objectType" => "Activity"
                ]
            ],
            "grouping" => [
                [
                    "id" => "https://".$_POST["team_domain"].".slack.com",
                    "definition" => [
                        "name" => [
                            "en" => $_POST["team_domain"]." Slack"
                        ],
                        "type" => "http://id.tincanapi.com/activitytype/site"
                    ],
                    "objectType" => "Activity"
                ]
            ],
           "category" => [
                [
                    "id" => "https://.slack.com/",
                    "definition" => [
                        "name" => [
                            "en" => "Slack"
                        ],
                        "type" => "http://id.tincanapi.com/activitytype/source"
                    ]
                ]
            ]
    ]
    ]
);

$timestamp = date('c'); 

$statement = new TinCan\Statement(
    [
        'actor' => $actor,
        'verb'  => $verb,
        'object' => $activity,
        'result' => $result,
        'context' => $context,
        'timestamp' => $timestamp
    ]
);

$response = $lrs->saveStatement($statement);

# If statement saved, calculate daily percentage value
if ($response->success) {

    $url = str_replace('lrs/', 'aggregation/csv', $CFG->lrs->endpoint);

    $http = array(
        'max_redirects' => 0,
        'request_fulluri' => 1,
        'ignore_errors' => true,
        'method' => 'GET',
        'header' => [
            'Authorization: Basic '.base64_encode($CFG->lrs->key.':'.$CFG->lrs->secret)
        ]
    );

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
                  $_POST["user_id"]
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

    $context = stream_context_create(array( 'http' => $http ));
    $fp = fopen($url, 'rb', false, $context);
    if (! $fp) {
        throw new \Exception("Request failed: $php_errormsg");
    }
    $metadata = stream_get_meta_data($fp);
    $content  = stream_get_contents($fp);
    $responseCode = (int)explode(' ', $metadata["wrapper_data"][0])[1];

    fclose($fp);

    $message = "";

    if ($responseCode === 200) {
        $rowsAsStr = explode(PHP_EOL, $content);
        $csvData =[];
        foreach ($rowsAsStr as $row) {
            array_push($csvData, explode(',', $row));
        }

        // Today is not included in the filter.
        $activeDays = $csvData[1][1] + 1;

        $daysLookUp = [
            "default" => [
                "Sunday" => 20,
                "Monday" => 21,
                "Tuesday" => 22,
                "Wednesday" => 22,
                "Thursday" => 22,
                "Friday" => 22,
                "Saturday" => 21
            ],
            "jenagarrett" => [
                "Sunday" => 12,
                "Monday" => 12,
                "Tuesday" => 13,
                "Wednesday" => 14,
                "Thursday" => 14,
                "Friday" => 13,
                "Saturday" => 12
            ]
    	];

        $totalDays;
        if (isset($daysLookUp[$_POST["user_name"]])){
            $totalDays = $daysLookUp[$_POST["user_name"]][date("l")];
        } else {
            $totalDays = $daysLookUp["default"][date("l")];
        }

        $daysPercent = ($activeDays / $totalDays) * 100;
        $message = 'Great job @'.$_POST["user_name"]."! You slacked your status ".number_format($daysPercent, 2)."% of days in the last month!";
    } else {
    	$message = str_replace('@name', '@'.$_POST["user_name"], $CFG->slack->responses[array_rand($CFG->slack->responses)]);
    }
} else {
    $message = "Error statement not sent: " . $response->content;
}

# Finally, post result to response_url
$url = $_POST["response_url"];
$options = array(
        'http' => array(
        'header'  => "Content-type: application/json\r\n",
        'method'  => 'POST',
        'content' => '{"response_type": "in_channel", "text":"'.$message.'"}'
    )
);
$context  = stream_context_create($options);
$result = file_get_contents($url, false, $context);

?>
