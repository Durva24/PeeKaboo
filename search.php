<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);

$start = microtime(true);

// Get query parameter
$query = $_REQUEST["q"] ?? "What's new in old tech?";

// Always use the 'llama2-70b' model
$model = "llama2-70b-4096";

// Function to perform search with Serper API
function search_with_serper($query, $num_sources = 15) {
    $SERPER_KEY = "e057f2e5af486fc09917e3e0964783c73fd33358"; // Replace with your actual API key

    $curl = curl_init();
    $request = array("q" => $query, "num" => $num_sources);
    $data = json_encode($request);

    $headers = [
        'X-API-KEY: ' . $SERPER_KEY,
        'Content-Type: application/json'
    ];

    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_URL, "https://google.serper.dev/search");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

    $response = curl_exec($curl);
    curl_close($curl);

    $jsonContent = json_decode($response, true);

    $snippets = [];

    if (isset($jsonContent['knowledgeGraph'])) {
        $url = $jsonContent['knowledgeGraph']['descriptionUrl'] ?? $jsonContent['knowledgeGraph']['website'] ?? null;
        $snippet = $jsonContent['knowledgeGraph']['description'] ?? null;
        if ($url && $snippet) {
            $snippets[] = [
                'url' => $url,
                'snippet' => $snippet,
            ];
        }
    }

    if (isset($jsonContent['answerBox'])) {
        $url = $jsonContent['answerBox']['link'] ?? $jsonContent['answerBox']['url'] ?? null;
        $snippet = $jsonContent['answerBox']['snippet'] ?? $jsonContent['answerBox']['answer'] ?? null;
        if ($url && $snippet) {
            $snippets[] = [
                'url' => $url,
                'snippet' => $snippet,
            ];
        }
    }

    if (isset($jsonContent['organic'])) {
        foreach ($jsonContent['organic'] as $c) {
            $snippets[] = [
                'url' => $c['link'],
                'snippet' => $c['snippet'] ?? '',
            ];
        }
    }

    return $snippets;
}

// Function to search images with Serper API
function search_images_with_serper($query, $num_images = 4) {
    $SERPER_KEY = "e057f2e5af486fc09917e3e0964783c73fd33358"; // Replace with your actual API key

    $curl = curl_init();
    $request = array("q" => $query, "num" => $num_images);
    $data = json_encode($request);

    $headers = [
        'X-API-KEY: ' . $SERPER_KEY,
        'Content-Type: application/json'
    ];

    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_URL, "https://google.serper.dev/images");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

    $response = curl_exec($curl);
    curl_close($curl);

    $jsonContent = json_decode($response, true);

    $images = [];

    if (isset($jsonContent['images'])) {
        foreach (array_slice($jsonContent['images'], 0, 4) as $image) {
            $images[] = [
                'url' => $image['imageUrl'],
            ];
        }
    }

    return $images;
}

// Function to setup curl for LLM request
function setup_curl_to_llm($query, $context, $max_tokens, $stream = false, $model = "llama2-70b-4096", $temperature = 1) {
    $GROQ_KEY = "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";

    $LLM_ENDPOINT = "https://api.groq.com/openai/v1/chat/completions";
    $LLM_KEY = $GROQ_KEY;

    $system = (object)[
        "role" => "system",
        "content" => $context
    ];

    $user = (object)[
        "role" => "user",
        "content" => $query
    ];

    $request = [
        "model" => $model,
        "messages" => [$system, $user],
        "temperature" => $temperature,
        "stream" => $stream,
        "max_tokens" => $max_tokens
    ];
    $data = json_encode($request);

    $curl = curl_init();
    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer " . $LLM_KEY,
    ];

    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_URL, $LLM_ENDPOINT);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

    if ($stream) {
        $callback = function ($ch, $str) {
            $chunks = explode("data: ", $str);
            foreach ($chunks as $chunk) {
                if (!empty($chunk) && $chunk !== "[DONE]") {
                    $json = json_decode($chunk);
                    if (isset($json->choices)) {
                        $choice = $json->choices[0];
                        if (isset($choice->delta) && isset($choice->delta->content)) {
                            echo $choice->delta->content;
                            flush();
                            ob_flush();
                        }
                    }
                }
            }
            return strlen($str);
        };

        curl_setopt($curl, CURLOPT_WRITEFUNCTION, $callback);
    }

    return $curl;
}

// Function to get snippets for the prompt
function get_snippets_for_prompt($snippets) {
    $snippets_context = "";
    foreach ($snippets as $i => $s) {
        $snippets_context .= "[citation:" . ($i + 1) . "] " . $s['snippet'];

        if ($i < count($snippets) - 1) {
            $snippets_context .= "\n\n";
        }
    }

    return $snippets_context;
}

// Function to setup prompt for getting an answer
function setup_get_answer_prompt($snippets, $images) {
    $starting_context = <<<'EOD'
You are an assistant written by Durva Dongre. You will be given a question. Please respond with the following:

1. A detailed, comprehensive answer to the question. It must be accurate, high-quality, and expertly written in a positive, interesting, and engaging manner. The answer should be informative and in the same language as the user question. Aim for at least 300 words in your response.

2. After your main answer, provide a section titled "Image Descriptions" where you describe how each of the provided images relates to the topic. Use the format: "Image X: [Brief description and relevance to the topic]"

3. Finally, provide 5 related follow-up questions. Start this section with "==== RELATED ====" and then list the questions in a JSON array format. Each related question should be no longer than 15 words. They should be based on the user's original question, the citations given in the context, and the provided images. Do not repeat the original question. Make sure to determine the main subject from the user's original question. That subject needs to be in any related question, so the user can ask it standalone.

For all parts of your response, you will be provided with a set of citations to the question. Each will start with a reference number like [citation:x], where x is a number. Always use the related citations and cite the citation at the end of each sentence in the format [citation:x]. If a sentence comes from multiple citations, please list all applicable citations, like [citation:2][citation:3].

Here are the provided citations:
EOD;

    $image_context = "\nHere are descriptions of relevant images:\n";
    foreach ($images as $index => $image) {
        $image_context .= "Image " . ($index + 1) . ": [Brief description and relevance to the topic]\n";
    }

    $final_context = "Use the provided information to create a comprehensive and engaging response.";

    $full_context = $starting_context . "\n\n" . get_snippets_for_prompt($snippets) . "\n\n" . $image_context . "\n\n" . $final_context;
    return $full_context;
}

// Function to execute multiple curl requests simultaneously
function execute_multi_curl(...$curlArray) {
    $mh = curl_multi_init();
    foreach ($curlArray as $curl) {
        curl_multi_add_handle($mh, $curl);
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running);

    $responses = [];
    foreach ($curlArray as $curl) {
        $responses[] = curl_multi_getcontent($curl);
        curl_multi_remove_handle($mh, $curl);
    }
    curl_multi_close($mh);
    return $responses;
}

// Execute search and image retrieval
$snippets = search_with_serper($query);
$images = search_images_with_serper($query);

$search_end = microtime(true);

// Set up the prompt context
$answer_prompt_context = setup_get_answer_prompt($snippets, $images);

// Set up the curl request for the language model
$answer_curl = setup_curl_to_llm($query, $answer_prompt_context, 3000, true, $model, 0.7);

// Fetch the responses
$responses = execute_multi_curl($answer_curl);

// Handle empty or invalid responses
$summary = $responses[0] ? json_decode($responses[0], true) : null;

echo json_encode([
    "summary" => $summary, // Ensure the summary is a detailed response
    "images" => array_map(fn($img) => ['url' => $img['url']], $images), // Only returning image URLs
    "metadata" => [
        "query" => $query,
        "model" => $model,
        "duration" => [
            "search" => number_format(($search_end - $start), 2) . 's',
            "llm" => number_format((microtime(true) - $search_end), 2) . 's',
            "total" => number_format((microtime(true) - $start), 2) . 's'
        ]
    ]
], JSON_PRETTY_PRINT);
?>
