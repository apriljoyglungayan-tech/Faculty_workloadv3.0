<?php
declare(strict_types=1);

$configPath = getenv('APP_CONFIG_PATH') ?: __DIR__ . '/private-config.php';
$privateConfig = is_file($configPath) ? require $configPath : [];
if (!is_array($privateConfig)) {
    $privateConfig = [];
}

class AiGenerationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 502)
    {
        parent::__construct($message);
    }
}

function dotenv_value(string $key): ?string
{
    static $values = null;
    if ($values === null) {
        $values = [];
        $path = __DIR__ . DIRECTORY_SEPARATOR . '.env';
        if (is_file($path) && is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines ?: [] as $line) {
                if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $match)) {
                    continue;
                }
                $value = trim($match[2]);
                if (strlen($value) >= 2 && (
                    ($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'"))
                )) {
                    $value = substr($value, 1, -1);
                }
                $values[$match[1]] = $value;
            }
        }
    }
    return $values[$key] ?? null;
}

function config_value(string $key, mixed $fallback = null): mixed
{
    global $privateConfig;
    if (array_key_exists($key, $privateConfig) && $privateConfig[$key] !== '' && $privateConfig[$key] !== null) {
        return $privateConfig[$key];
    }
    $value = getenv($key);
    if ($value !== false && trim($value) !== '') {
        return $value;
    }
    $value = dotenv_value($key);
    if ($value !== null && $value !== '') {
        return $value;
    }
    return $privateConfig[$key] ?? $fallback;
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function request_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        respond(400, ['success' => false, 'error' => 'Request body must be valid JSON.']);
    }
    return $body;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = (string) config_value('DB_HOST', '');
    $name = (string) config_value('DB_NAME', '');
    $user = (string) config_value('DB_USER', '');
    $password = (string) config_value('DB_PASSWORD', '');
    if ($host === '' || $name === '' || $user === '') {
        throw new RuntimeException('MySQL is not configured. Set DB_HOST, DB_NAME, DB_USER, and DB_PASSWORD in private server configuration.');
    }

    $port = (int) config_value('DB_PORT', 3306);
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    return $pdo;
}

function default_profile(string $name, string $email): array
{
    return [
        'name' => $name,
        'rank' => 'FACULTY MEMBER',
        'college' => 'College of Computing Studies, Information and Communication Technology (CCSICT)',
        'department' => 'Department of Computer Science & Information Technology',
        'academicYear' => '2026-2027',
        'semester' => '1st Semester',
        'departmentChair' => '',
        'collegeDean' => '',
        'vpaa' => 'OFFICE OF ACADEMIC AFFAIRS',
        'email' => $email,
    ];
}

function demo_accounts(): array
{
    $adminEmail = strtolower(trim((string) config_value('DEMO_ADMIN_EMAIL', '')));
    $adminPassword = (string) config_value('DEMO_ADMIN_PASSWORD', '');
    $facultyEmail = strtolower(trim((string) config_value('DEMO_FACULTY_EMAIL', '')));
    $facultyPassword = (string) config_value('DEMO_FACULTY_PASSWORD', '');
    $facultyName = (string) config_value('DEMO_FACULTY_NAME', 'Faculty Demo Account');

    return [
        [
            'email' => $adminEmail,
            'password' => $adminPassword,
            'role' => 'admin',
            'name' => 'Portal Administrator',
            'profile' => default_profile('Portal Administrator', $adminEmail),
        ],
        [
            'email' => $facultyEmail,
            'password' => $facultyPassword,
            'role' => 'faculty',
            'name' => $facultyName,
            'profile' => default_profile($facultyName, $facultyEmail),
        ],
    ];
}

function seed_demo_accounts(PDO $pdo): void
{
    $accounts = demo_accounts();
    if ($accounts[0]['email'] === '' || $accounts[0]['email'] === $accounts[1]['email']) {
        throw new RuntimeException('Configure distinct administrator and faculty demo email addresses in private server settings.');
    }
    foreach ($accounts as $account) {
        if (
            !filter_var($account['email'], FILTER_VALIDATE_EMAIL)
            || strlen($account['password']) < 12
        ) {
            throw new RuntimeException('Configure distinct valid demo emails and unique passwords of at least 12 characters in private server settings.');
        }
    }

    $find = $pdo->prepare('SELECT id, role FROM users WHERE email = ? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO users (id, email, password_hash, role, profile_json) VALUES (?, ?, ?, ?, ?)');
    foreach ($accounts as $account) {
        $find->execute([$account['email']]);
        $existing = $find->fetch();
        if ($existing) {
            if ($existing['role'] !== $account['role']) {
                throw new RuntimeException('A configured demo email is already assigned a different role in MySQL.');
            }
            continue;
        }
        $insert->execute([
            bin2hex(random_bytes(16)),
            $account['email'],
            password_hash($account['password'], PASSWORD_DEFAULT),
            $account['role'],
            json_encode($account['profile'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }
}

function workspace_for(PDO $pdo, string $userId): array
{
    $statement = $pdo->prepare('SELECT data_key, data_json FROM user_data WHERE user_id = ?');
    $statement->execute([$userId]);
    $workspace = [];
    foreach ($statement->fetchAll() as $row) {
        $decoded = json_decode($row['data_json'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Stored workspace data could not be decoded. Contact the system administrator.');
        }
        $workspace[$row['data_key']] = $decoded;
    }
    return $workspace;
}

function current_user(PDO $pdo): ?array
{
    $token = $_COOKIE['fwp_session'] ?? '';
    if (!is_string($token) || strlen($token) < 40) {
        return null;
    }
    $query = $pdo->prepare(
        'SELECT u.id, u.email, u.role, u.profile_json
         FROM auth_sessions s JOIN users u ON u.id = s.user_id
         WHERE s.token_hash = ? AND s.expires_at > UTC_TIMESTAMP() LIMIT 1'
    );
    $query->execute([hash('sha256', $token)]);
    $user = $query->fetch();
    if (!$user) {
        return null;
    }
    $profile = json_decode($user['profile_json'], true);
    if (!is_array($profile)) {
        throw new RuntimeException('Stored account profile is invalid. Contact the system administrator.');
    }
    return [
        'id' => $user['id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'profile' => $profile,
    ];
}

function set_auth_cookie(string $token, int $expires): void
{
    $secure = config_value('COOKIE_SECURE', null);
    if ($secure === null) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    } else {
        $secure = filter_var($secure, FILTER_VALIDATE_BOOLEAN);
    }
    setcookie('fwp_session', $token, [
        'expires' => $expires,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function start_auth(PDO $pdo, string $userId): void
{
    $token = bin2hex(random_bytes(32));
    $expires = time() + 60 * 60 * 24 * 7;
    $insert = $pdo->prepare('INSERT INTO auth_sessions (token_hash, user_id, expires_at) VALUES (?, ?, ?)');
    $insert->execute([
        hash('sha256', $token),
        $userId,
        gmdate('Y-m-d H:i:s', $expires),
    ]);
    set_auth_cookie($token, $expires);
}

function auth_response(PDO $pdo, array $user): array
{
    return [
        'success' => true,
        'user' => [
            'email' => $user['email'],
            'role' => $user['role'],
            'profile' => $user['profile'],
        ],
        'workspace' => workspace_for($pdo, $user['id']),
    ];
}

function json_string(mixed $value): string
{
    $result = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($result === false) {
        throw new RuntimeException('Workspace data could not be encoded.');
    }
    return $result;
}

function save_workspace(PDO $pdo, string $userId, array $workspace): void
{
    $allowed = ['schedule', 'syllabi', 'reports', 'larReports'];
    $pdo->beginTransaction();
    try {
        if (array_key_exists('profile', $workspace)) {
            if (!is_array($workspace['profile'])) {
                throw new InvalidArgumentException('Profile data must be an object.');
            }
            $profile = $workspace['profile'];
            $update = $pdo->prepare('UPDATE users SET profile_json = ? WHERE id = ?');
            $update->execute([json_string($profile), $userId]);
        }

        $upsert = $pdo->prepare(
            'INSERT INTO user_data (user_id, data_key, data_json) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE data_json = VALUES(data_json)'
        );
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $workspace)) {
                continue;
            }
            if (!is_array($workspace[$key])) {
                throw new InvalidArgumentException("Workspace field '{$key}' must be an array.");
            }
            $upsert->execute([$userId, $key, json_string($workspace[$key])]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function seed_schedule_catalog(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM schedule_datasets')->fetchColumn();
    if ($count > 0) {
        return;
    }
    $courses = [
        ['CS 111', 'Introduction to Computing', 'BSCS 1A-DM'],
        ['TM INST 4', 'Data Science Analytics', 'BSTM 3B'],
        ['EMC 111', 'Introduction to Computing', 'BSEMC-DA 1A'],
        ['IT INST 3', 'Data Science Analytics', 'BSIT 3A-BPO'],
        ['MA INST 4', 'Data Science Analytics', 'BSMA 3A'],
        ['LM INST 4', 'Data Science Analytics', 'BSLM 3A'],
        ['ELS INST 3', 'Data Science Analytics', 'BA-ELS 3'],
        ['IT 210', 'Web Systems and Technologies', 'BSIT 2A'],
        ['CS 220', 'Data Structures and Algorithms', 'BSCS 2A'],
        ['IS 305', 'Systems Analysis and Design', 'BSIS 3A'],
        ['IT 315', 'Information Assurance', 'BSIT 3B'],
        ['CS 410', 'Capstone Project', 'BSCS 4A'],
    ];
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    $slots = [
        ['07:00 AM', '10:00 AM'],
        ['08:00 AM', '11:00 AM'],
        ['10:00 AM', '01:00 PM'],
        ['01:00 PM', '04:00 PM'],
        ['02:00 PM', '05:00 PM'],
    ];
    $insert = $pdo->prepare(
        'INSERT INTO schedule_datasets
         (id, course_code, course_title, section, day_of_week, start_time, end_time, credit_units, room_or_platform, modality_default)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $pdo->beginTransaction();
    try {
        foreach ($courses as $courseIndex => [$code, $title, $section]) {
            foreach ($days as $dayIndex => $day) {
                [$start, $end] = $slots[($courseIndex + $dayIndex) % count($slots)];
                $insert->execute([
                    sprintf('demo-%02d-%02d', $courseIndex + 1, $dayIndex + 1),
                    $code,
                    $title,
                    $section,
                    $day,
                    $start,
                    $end,
                    3,
                    $day === 'Friday' ? 'Google Classroom / Zoom' : 'CCSICT Room ' . (200 + (($courseIndex + $dayIndex) % 12)),
                    $day === 'Friday' ? 'Synchronous' : 'Face-to-Face',
                ]);
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function execute_json_post(string $url, array $headers, array $payload, int $timeout): array
{
    $curl = curl_init($url);
    $options = [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_TIMEOUT => $timeout,
    ];
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
        $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }
    curl_setopt_array($curl, $options);
    $response = curl_exec($curl);
    $result = [
        'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
        'errno' => curl_errno($curl),
        'curlError' => curl_error($curl),
        'data' => is_string($response) ? json_decode($response, true) : null,
    ];
    curl_close($curl);
    return $result;
}

function search_ched_sources(array $body): array
{
    $apiKey = trim((string) config_value('TAVILY_API_KEY', ''));
    if ($apiKey === '' || stripos($apiKey, 'configure') !== false || stripos($apiKey, 'replace') !== false) {
        throw new AiGenerationException(
            'CHED source search is not configured. Add TAVILY_API_KEY to the server configuration or environment.',
            503
        );
    }
    $program = trim((string) ($body['program'] ?? ''));
    if ($program === '') {
        throw new InvalidArgumentException('Enter the exact degree program before generating a syllabus.');
    }

    $broadProgram = trim((string) preg_replace('/\s*\([^)]*\)/u', '', $program));
    if ($broadProgram === '') {
        $broadProgram = $program;
    }
    $queries = array_values(array_unique([
        $program . ' CHED CMO PSG',
        $broadProgram . ' CHED memorandum order program standards guidelines Philippines',
        'site:ched.gov.ph ' . $broadProgram . ' CMO',
    ]));
    $sources = [];
    $relevantSourceCount = 0;
    $programKeywords = preg_split('/[^a-z0-9]+/i', strtolower($broadProgram), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $programKeywords = array_values(array_diff($programKeywords, [
        'bachelor', 'degree', 'program', 'programs', 'science', 'arts', 'of', 'in', 'and',
    ]));
    foreach ($queries as $query) {
        $result = [];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $result = execute_json_post(
                'https://api.tavily.com/search',
                [],
                [
                    'api_key' => $apiKey,
                    'query' => $query,
                    'topic' => 'general',
                    'search_depth' => 'basic',
                    'max_results' => 8,
                ],
                25
            );
            if ($result['errno'] !== 6 || $attempt === 2) {
                break;
            }
            usleep(250000 * ($attempt + 1));
        }
        $status = $result['status'];
        $providerMessage = (string) (
            $result['data']['detail']['message']
            ?? $result['data']['message']
            ?? $result['data']['error']
            ?? ''
        );
        if ($result['errno'] !== 0) {
            error_log('Tavily request failed (cURL ' . $result['errno'] . '): ' . $result['curlError']);
            $message = $result['errno'] === 6
                ? 'The server could not resolve Tavily’s address (DNS lookup failed). Check the computer’s internet/DNS connection and try again.'
                : 'Could not reach Tavily to search for CHED sources. Check the server connection and try again.';
            throw new AiGenerationException($message);
        }
        if ($status === 401 || $status === 403) {
            error_log('Tavily rejected request (' . $status . '): ' . $providerMessage);
            throw new AiGenerationException('Tavily rejected the search key. Check that TAVILY_API_KEY is valid and active.', 503);
        }
        if ($status === 429) {
            error_log('Tavily quota/rate limit (' . $status . '): ' . $providerMessage);
            throw new AiGenerationException('Tavily search quota is exhausted or rate-limited. Check your Tavily account quota and try again later.', 429);
        }
        if ($status < 200 || $status >= 300) {
            error_log('Tavily request failed (' . $status . '): ' . $providerMessage);
            throw new AiGenerationException('Tavily could not search for CHED sources. Check the search API configuration and try again.');
        }

        foreach ($result['data']['results'] ?? [] as $item) {
            if (!is_array($item) || !is_string($item['url'] ?? null) || !preg_match('#^https?://#i', $item['url'])) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? 'Search result'));
            $snippet = trim((string) ($item['content'] ?? ''));
            $sourceText = strtolower($title . ' ' . $item['url'] . ' ' . $snippet);
            $host = strtolower((string) parse_url($item['url'], PHP_URL_HOST));
            $hasProgramMatch = false;
            foreach ($programKeywords as $keyword) {
                if (strlen($keyword) >= 3 && str_contains($sourceText, $keyword)) {
                    $hasProgramMatch = true;
                    break;
                }
            }
            $hasChedSignal = preg_match('/\b(ched|cmo|psg|memorandum|policies|standards|guidelines)\b/i', $sourceText) === 1;
            $isOfficialChed = $host === 'ched.gov.ph' || str_ends_with($host, '.ched.gov.ph');
            $isRelevant = $isOfficialChed || ($hasChedSignal && $hasProgramMatch);
            $isNewRelevantSource = $isRelevant && !($sources[$item['url']]['relevant'] ?? false);
            $sources[$item['url']] = [
                'title' => $title,
                'url' => $item['url'],
                'snippet' => $snippet,
                'relevant' => $isRelevant || ($sources[$item['url']]['relevant'] ?? false),
            ];
            if ($isNewRelevantSource) {
                $relevantSourceCount++;
            }
        }
        if ($relevantSourceCount >= 3) {
            break;
        }
    }
    if (!$sources) {
        throw new AiGenerationException(
            'Tavily found no online results after trying broad searches for this degree program and official CHED CMO/PSG sources. Check the program name or verify the applicable PSG manually.'
        );
    }
    $sources = array_values($sources);
    usort($sources, static fn (array $left, array $right): int => (int) $right['relevant'] <=> (int) $left['relevant']);
    return array_map(
        static fn (array $source): array => [
            'title' => $source['title'],
            'url' => $source['url'],
            'snippet' => $source['snippet'],
        ],
        array_slice($sources, 0, 8)
    );
}

function generate_syllabus_with_gemini(array $body): array
{
    $apiKey = trim((string) config_value('GEMINI_API_KEY', ''));
    if ($apiKey === '' || stripos($apiKey, 'configure') !== false || stripos($apiKey, 'replace') !== false) {
        throw new AiGenerationException(
            'Syllabus generation is not configured. Add GEMINI_API_KEY and TAVILY_API_KEY to the server configuration or environment.',
            503
        );
    }
    if (!function_exists('curl_init')) {
        throw new AiGenerationException('The PHP cURL extension is required for syllabus generation.', 503);
    }

    $sources = search_ched_sources($body);
    $model = trim((string) config_value('GEMINI_MODEL', 'gemini-3.6-flash'));
    $prompt = "Prepare an editable draft course syllabus for a Philippine higher education institution. Use the supplied Tavily web-search results as research leads for the current CHED Policies, Standards and Guidelines (PSG) that apply to the exact degree program and course. Search results are not authoritative proof by themselves: prioritize official CHED sources, verify that a CMO actually applies to this program, and do not invent requirements or claim government approval/accreditation. If applicability cannot be established from the supplied source information, state that an academic reviewer must confirm the CMO and do not present an unverified CMO as confirmed.\n\nAlign the syllabus content to the selected degree program (not merely the course title): make the course description, course objectives, CLOs, program-outcome mappings, weekly topics, learning activities, assessments, grading, and references relevant to the program and course level. Use the supplied focus topics and local academic details. CLOs must be measurable and student-centered; map each CLO to relevant program outcome codes with appropriate I/E/D level. Make all 18 course-plan weeks sequential (1 through 18), and align each week's LILOs, CLO codes, teaching-learning activities, assessment tasks, and resources. Every coursePlan week must have lilos, cloCodes, tlas, ats, and resources as JSON arrays (never a single string), and tlas entries must contain modality and activity. Keep each week concise to fit the complete syllabus in one response. Do not invent institutional facts, outcome codes, CMO clauses, credit requirements, or grading policies; mark institution-specific items for faculty review when not supplied.\n\nReturn exactly one valid JSON object matching the CourseSyllabus fields. Critical exact shapes: qualityPolicy, goalsOfCollege, courseObjectives, classroomPolicies, and each week’s lilos, cloCodes, ats, and resources are arrays of strings; institutionalOutcomes and graduateAttributes are arrays of {code,title,description}; programOutcomes are arrays of {code,statement}; courseLearningOutcomes are arrays of {code,statement,poMapping:[{poCode,level}],gaCodes:[string]}; assessmentGradingSystem is an object {lectureWeight:number,labWeight:number|null,components:[{name,percentage:number}]}; references is an array of {category,items:[string]}; sdgs is an array of {code,title,description}; gradeEquivalents is an array of {range,grade}; aiPolicy is null or {policyName,threshold,tiers:[{tier,description}]}; signatories is {preparedBy,reviewedBy,approvedBy,date}. All 18 coursePlan entries must contain weekNumber, weekRange, topic, lilos, cloCodes, tlas:[{modality,activity}], ats, and resources. Use JSON values with exactly these types, not prose strings in place of objects or arrays. Do not fabricate source URLs; use the supplied search results only. Include no markdown fences or prose outside the JSON. Never include the phrases 1978, ISU QUALITY ASSURANCE, or Seal Aligned.\n\nCourse request: " . json_string($body) . "\n\nTavily search results: " . json_string($sources);
    $response = execute_json_post(
        'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent',
        ['x-goog-api-key: ' . $apiKey],
        [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.2,
                'maxOutputTokens' => 24576,
            ],
        ],
        120
    );
    $status = $response['status'];
    $providerMessage = (string) ($response['data']['error']['message'] ?? '');
    if ($response['errno'] !== 0) {
        error_log('Gemini syllabus request failed (cURL ' . $response['errno'] . '): ' . $response['curlError']);
        throw new AiGenerationException('Could not reach Gemini. Check the server connection and try again.');
    }
    if ($status === 429) {
        error_log('Gemini syllabus quota/rate limit (' . $status . '): ' . $providerMessage);
        throw new AiGenerationException('Gemini API quota is exhausted or rate-limited. Check the Google AI Studio project quota and try again later.', 429);
    }
    if ($status === 401 || $status === 403) {
        error_log('Gemini rejected syllabus request (' . $status . '): ' . $providerMessage);
        throw new AiGenerationException('Gemini rejected the API key or request. Check that GEMINI_API_KEY is valid and the Generative Language API is enabled.', 503);
    }
    if ($status < 200 || $status >= 300) {
        error_log('Gemini syllabus request failed (' . $status . '): ' . $providerMessage);
        throw new AiGenerationException(
            $status >= 500
                ? 'Gemini is temporarily unavailable. Wait a few minutes and try again.'
                : 'Gemini could not process the syllabus request. Check the configured model and API access.'
        );
    }

    $candidate = $response['data']['candidates'][0] ?? [];
    if (($candidate['finishReason'] ?? '') === 'MAX_TOKENS') {
        throw new AiGenerationException('Gemini reached its output limit before completing the syllabus. Reduce the requested details and try again.');
    }
    $content = '';
    foreach ($candidate['content']['parts'] ?? [] as $part) {
        if (is_string($part['text'] ?? null)) {
            $content .= $part['text'];
        }
    }
    $generated = $content !== '' ? json_decode($content, true) : null;
    if (!is_array($generated)) {
        $jsonStart = strpos($content, '{');
        $jsonEnd = strrpos($content, '}');
        if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
            $generated = json_decode(substr($content, $jsonStart, $jsonEnd - $jsonStart + 1), true);
        }
    }
    if (!is_array($generated)) {
        error_log('Gemini returned invalid syllabus data.');
        throw new AiGenerationException('Gemini returned invalid syllabus data. Try generating again.');
    }
    $generated = validate_generated_syllabus($generated, $body);
    $generated['references'][] = [
        'category' => 'Online sources found by Tavily; verify applicability before adoption',
        'items' => array_map(
            static fn (array $source): string => $source['title'] . ' — ' . $source['url'] . ($source['snippet'] !== '' ? ' — ' . $source['snippet'] : ''),
            $sources
        ),
    ];
    return $generated;
}

function call_gemini(array $body, string $type): array
{
    if ($type === 'syllabus') {
        return generate_syllabus_with_gemini($body);
    }
    $apiKey = (string) config_value('GEMINI_API_KEY', '');
    if ($apiKey === '' || stripos($apiKey, 'configure') !== false || stripos($apiKey, 'replace') !== false) {
        throw new AiGenerationException(
            'AI syllabus generation is not configured. Add a valid GEMINI_API_KEY to the server environment or private-config.php, then restart the PHP server.',
            503
        );
    }
    if (!function_exists('curl_init')) {
        throw new AiGenerationException('The PHP cURL extension is required for AI generation.', 503);
    }
    $model = (string) config_value('GEMINI_MODEL', 'gemini-3.6-flash');
    $isSyllabus = $type === 'syllabus';
    $prompt = match ($type) {
        'syllabus' => "You are preparing an editable draft course syllabus for a Philippine higher education institution. Before writing, use Google Search to research the current CHED Policies, Standards and Guidelines (PSG) that apply to the exact degree program and course. Prefer primary CHED sources on ched.gov.ph; use a university-hosted copy only when the CHED source is unavailable, and identify it as a copy. Verify the CMO number/title applies to this program. Do not treat the supplied CMO preset as proof, invent requirements, or claim government approval/accreditation. If the applicable PSG cannot be verified online, say so plainly in the references and note that an academic reviewer must confirm the CMO.\n\nAlign the syllabus content to the selected degree program (not merely the course title): make the course description, course objectives, CLOs, program-outcome mappings, weekly topics, learning activities, assessments, grading, and references relevant to the program and course level. Use the supplied focus topics and local academic details. CLOs must be measurable and student-centered; map each CLO to relevant program outcome codes with appropriate I/E/D level. Make all 18 course-plan weeks sequential (1 through 18), and align each week's LILOs, CLO codes, teaching-learning activities, assessment tasks, and resources. Do not invent institutional facts, outcome codes, CMO clauses, credit requirements, or grading policies; mark institution-specific items for faculty review when not supplied.\n\nReturn exactly one valid JSON object matching the CourseSyllabus fields: documentCode, revisionNumber, headerInfo (courseCode, courseTitle, program, chedCmo, creditUnits, prerequisites, college, department, semester, academicYear, instructorName), vision, mission, qualityPolicy, institutionalOutcomes, graduateAttributes, courseDescription, goalsOfCollege, programOutcomes, courseLearningOutcomes, courseObjectives, sdgs, assessmentGradingSystem, courseAssessmentAttainmentMatrix, coursePlan (exactly 18 entries with weekNumber, weekRange, topic, lilos, cloCodes, tlas containing modality and activity, ats, resources), cqpdcaMechanism, aiPolicy, gradingFormula, gradeEquivalents, classroomPolicies, references (category and items), revisionHistory, signatories, createdAt, and lastUpdated. Use JSON values with the correct object/array/string/number types. References must include the verified CHED source title and URL(s). Include no markdown fences or prose outside the JSON. Never include the phrases 1978, ISU QUALITY ASSURANCE, or Seal Aligned. Request: " . json_string($body),
        'accomplishment-report' => 'Return one JSON accomplishment report with header, executiveSummary, pillars (each with pillarId, pillarTitle, description, accomplishments), challengesAndInterventions, dailyLogs, totalLecHours, totalLabHours, signatories, createdAt. Use valid JSON only. Never include the phrases 1978, ISU QUALITY ASSURANCE, or Seal Aligned. Input: ' . json_string($body),
        'learning-activity-report' => 'Return one JSON learner activity report with header, classesReported (include studentEngagement, intendedLearningOutcomes, accomplishments and meansOfVerification), nonTeachingActivities, overallDaySummary, certificationText, signatories, createdAt. Use valid JSON only. Never include the phrases 1978, ISU QUALITY ASSURANCE, or Seal Aligned. Input: ' . json_string($body),
        'refine' => 'Refine current JSON data according to instructions and preserve the same schema. Return valid JSON only. Never include the phrases 1978, ISU QUALITY ASSURANCE, or Seal Aligned. Input: ' . json_string($body),
        default => throw new InvalidArgumentException('Unsupported AI generation request.'),
    };

    $requestBody = [
        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'maxOutputTokens' => $isSyllabus ? 24576 : 8192,
            'temperature' => $isSyllabus ? 0.2 : 0.4,
        ],
    ];
    if ($isSyllabus) {
        $requestBody['tools'] = [['google_search' => new stdClass()]];
    } else {
        $requestBody['generationConfig']['responseMimeType'] = 'application/json';
    }

    $request = json_encode($requestBody, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($request)) {
        throw new AiGenerationException('The AI request could not be prepared. Check the syllabus fields and try again.', 422);
    }
    $curl = curl_init(
        'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent'
    );
    $curlOptions = [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
        CURLOPT_POSTFIELDS => $request,
        CURLOPT_TIMEOUT => 90,
    ];
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
        $curlOptions[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }
    curl_setopt_array($curl, $curlOptions);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlErrno = curl_errno($curl);
    $curlError = curl_error($curl);
    curl_close($curl);
    if (!is_string($response)) {
        error_log('Gemini request failed (cURL ' . $curlErrno . '): ' . ($curlError ?: 'network error'));
        throw new AiGenerationException('Could not reach the AI service. Check the internet connection and try again.');
    }
    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        $providerMessage = (string) ($decoded['error']['message'] ?? '');
        if ($status === 401) {
            error_log('Gemini rejected request (' . $status . '): ' . $providerMessage);
            throw new AiGenerationException(
                'The configured Gemini API key was rejected as invalid. Replace GEMINI_API_KEY in the server configuration with a valid key from a Google AI Studio project, then retry.',
                503
            );
        }
        if ($status === 403) {
            error_log('Gemini rejected request (' . $status . '): ' . $providerMessage);
            throw new AiGenerationException(
                'Gemini denied this request. Check that the Generative Language API is enabled for the key’s Google Cloud project and that the key restrictions allow it. If those settings are correct, check model and Google Search grounding access.',
                403
            );
        }
        if ($status === 400) {
            error_log('Gemini rejected request (' . $status . '): ' . $providerMessage);
            throw new AiGenerationException(
                'Gemini rejected the syllabus request. Check the PHP server error log for details, then verify the configured model and Google Search grounding support.'
            );
        }
        if ($status === 429) {
            error_log('Gemini quota/rate limit (' . $status . '): ' . $providerMessage);
            $quotaExceeded = stripos($providerMessage, 'exceeded your current quota') !== false
                || stripos($providerMessage, 'billing') !== false
                || stripos($providerMessage, 'resource exhausted') !== false;
            throw new AiGenerationException(
                $quotaExceeded
                    ? 'Gemini has exhausted the quota for this request, including Google Search grounding. Generation will work again after the project quota resets or billing/quota is enabled in Google AI Studio. Check the project’s rate limits before retrying.'
                    : 'Gemini is temporarily rate-limiting requests. Wait a minute and try again.',
                429
            );
        }
        if ($status === 404) {
            error_log('Gemini model unavailable (' . $status . '): ' . $providerMessage);
            throw new AiGenerationException(
                'The configured Gemini model is unavailable for this API key. Set GEMINI_MODEL to a model available to the Google AI Studio project, such as gemini-3.6-flash, then restart the PHP server.'
            );
        }
        if ($status === 503) {
            error_log('Gemini service unavailable (' . $status . '): ' . $providerMessage);
            throw new AiGenerationException('Gemini is temporarily unavailable or under heavy load. Wait a few minutes and try again.', 503);
        }
        error_log('Gemini request failed (' . $status . '): ' . $providerMessage);
        throw new AiGenerationException('The AI service could not complete this request. Try again shortly.');
    }
    $candidate = $decoded['candidates'][0] ?? [];
    if (($candidate['finishReason'] ?? '') === 'MAX_TOKENS') {
        throw new AiGenerationException('The AI response exceeded its output limit. Reduce the requested syllabus details and try again.');
    }
    $text = $candidate['content']['parts'][0]['text'] ?? null;
    if (is_string($text) && preg_match('/^\s*```(?:json)?\s*([\s\S]*?)\s*```\s*$/i', $text, $fenceMatch)) {
        $text = $fenceMatch[1];
    }
    $result = is_string($text) ? json_decode($text, true) : null;
    if (!is_array($result) && is_string($text)) {
        $jsonStart = strpos($text, '{');
        $jsonEnd = strrpos($text, '}');
        if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
            $result = json_decode(substr($text, $jsonStart, $jsonEnd - $jsonStart + 1), true);
        }
    }
    if (!is_array($result)) {
        error_log('Gemini returned an invalid syllabus response.');
        throw new AiGenerationException(
            'The AI returned an incomplete or invalid response. Try generating again; if it continues, reduce the requested syllabus details.'
        );
    }
    if ($isSyllabus) {
        $result = validate_generated_syllabus($result, $body);
        $groundingChunks = $candidate['groundingMetadata']['groundingChunks'] ?? [];
        $onlineSources = [];
        foreach (is_array($groundingChunks) ? $groundingChunks : [] as $chunk) {
            $web = $chunk['web'] ?? null;
            if (!is_array($web) || !is_string($web['uri'] ?? null)) {
                continue;
            }
            $uri = $web['uri'];
            if (!preg_match('#^https?://#i', $uri)) {
                continue;
            }
            $title = trim((string) ($web['title'] ?? 'CHED/PSG source'));
            $onlineSources[$uri] = $title . ' — ' . $uri;
        }
        if (!$onlineSources) {
            throw new AiGenerationException(
                'The AI could not verify an online CHED or program-specific PSG source for this draft. Try again later, or confirm the applicable CMO with your academic office.'
            );
        }
        $result['references'][] = [
            'category' => 'Online sources checked during AI generation',
            'items' => array_values($onlineSources),
        ];
    }
    return $result;
}

function syllabus_string_list(mixed $value): array
{
    if (is_string($value)) {
        $value = preg_split('/\r\n|\r|\n/u', $value) ?: [$value];
    }
    if (!is_array($value)) {
        return [];
    }
    $items = [];
    foreach ($value as $item) {
        if (is_string($item) || is_numeric($item)) {
            $text = trim((string) $item);
        } elseif (is_array($item)) {
            $text = trim((string) (
                $item['statement']
                ?? $item['description']
                ?? $item['activity']
                ?? $item['name']
                ?? $item['title']
                ?? ''
            ));
        } else {
            $text = '';
        }
        $text = preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $text) ?? $text;
        if ($text !== '') {
            $items[] = $text;
        }
    }
    return array_values(array_unique($items));
}

function normalize_syllabus_outcomes(mixed $value, string $prefix, bool $includeTitle): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : syllabus_string_list($value);
    }
    if (!is_array($value)) {
        return [];
    }
    if (
        isset($value['code']) || isset($value['poCode']) || isset($value['statement'])
        || isset($value['description']) || isset($value['title']) || isset($value['name'])
        || isset($value['outcome']) || isset($value['text'])
    ) {
        $value = [$value];
    }
    $outcomes = [];
    foreach (array_values($value) as $index => $item) {
        if (is_string($item)) {
            $item = ['statement' => $item];
            if (preg_match('/^\s*(' . preg_quote($prefix, '/') . '[\w.-]*)\s*[:.)-]\s*(.+)$/i', $item['statement'], $match)) {
                $item['code'] = strtoupper($match[1]);
                $item['statement'] = $match[2];
            }
        }
        if (!is_array($item)) {
            continue;
        }
        $code = trim((string) ($item['code'] ?? $item['poCode'] ?? '')) ?: $prefix . '-UNVERIFIED-' . ($index + 1);
        $statement = trim((string) (
            $item['statement']
            ?? $item['description']
            ?? $item['text']
            ?? $item['outcome']
            ?? $item['title']
            ?? ''
        ));
        if ($statement === '') {
            continue;
        }
        if ($includeTitle) {
            $outcomes[] = [
                'code' => $code,
                'title' => trim((string) ($item['title'] ?? $statement)),
                'description' => trim((string) ($item['description'] ?? $statement)),
            ];
        } else {
            $outcomes[] = ['code' => $code, 'statement' => $statement];
        }
    }
    return $outcomes;
}

function normalize_syllabus_clos(mixed $value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : syllabus_string_list($value);
    }
    if (!is_array($value)) {
        return [];
    }
    if (
        isset($value['code']) || isset($value['statement']) || isset($value['description'])
        || isset($value['outcome']) || isset($value['text'])
    ) {
        $value = [$value];
    }
    $clos = [];
    foreach (array_values($value) as $index => $item) {
        if (is_string($item)) {
            $item = ['statement' => $item];
        }
        if (!is_array($item)) {
            continue;
        }
        $statement = trim((string) (
            $item['statement']
            ?? $item['description']
            ?? $item['text']
            ?? $item['outcome']
            ?? ''
        ));
        if ($statement === '') {
            continue;
        }
        $mappings = $item['poMapping'] ?? $item['mapping'] ?? [];
        if (is_string($mappings)) {
            $mappings = syllabus_string_list($mappings);
        }
        $poMapping = [];
        foreach (is_array($mappings) ? $mappings : [] as $mappingCode => $mapping) {
            if (is_string($mapping)) {
                if (is_string($mappingCode) && preg_match('/^PO[\w.-]*$/i', $mappingCode)) {
                    $level = strtoupper(trim($mapping));
                    $poMapping[] = [
                        'poCode' => strtoupper($mappingCode),
                        'level' => in_array($level, ['I', 'E', 'D', '-'], true) ? $level : '-',
                    ];
                    continue;
                }
                if (preg_match('/^\s*(PO[\w.-]*)\s*[:(-]?\s*([IED-])?/i', $mapping, $match)) {
                    $poMapping[] = [
                        'poCode' => strtoupper($match[1]),
                        'level' => strtoupper($match[2] ?? '') ?: '-',
                    ];
                }
                continue;
            }
            if (!is_array($mapping)) {
                continue;
            }
            $poCode = trim((string) ($mapping['poCode'] ?? $mapping['code'] ?? ''));
            if ($poCode !== '') {
                $level = strtoupper(trim((string) ($mapping['level'] ?? '-')));
                $poMapping[] = [
                    'poCode' => $poCode,
                    'level' => in_array($level, ['I', 'E', 'D', '-'], true) ? $level : '-',
                ];
            }
        }
        $clos[] = [
            'code' => trim((string) ($item['code'] ?? 'CLO' . ($index + 1))),
            'statement' => $statement,
            'poMapping' => $poMapping,
            'gaCodes' => syllabus_string_list($item['gaCodes'] ?? []),
        ];
    }
    return $clos;
}

function normalize_syllabus_references(mixed $value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : [['category' => 'References for faculty review', 'items' => syllabus_string_list($value)]];
    }
    if (!is_array($value)) {
        return [];
    }
    if (isset($value['category']) || isset($value['items'])) {
        $entries = [];
        foreach ($value as $key => $entry) {
            if (is_int($key)) {
                $entries[] = $entry;
            }
        }
        $entries[] = [
            'category' => $value['category'] ?? 'References',
            'items' => $value['items'] ?? [],
        ];
        $value = $entries;
    }
    $references = [];
    foreach (array_values($value) as $reference) {
        if (is_string($reference)) {
            $reference = ['category' => 'References', 'items' => [$reference]];
        }
        if (!is_array($reference)) {
            continue;
        }
        $items = syllabus_string_list($reference['items'] ?? []);
        $category = trim((string) ($reference['category'] ?? 'References'));
        if ($items) {
            $references[] = ['category' => $category, 'items' => $items];
        }
    }
    return $references;
}

function syllabus_percentage(mixed $value): ?float
{
    if (is_numeric($value)) {
        return (float) $value;
    }
    if (is_string($value) && preg_match('/^\s*(\d+(?:\.\d+)?)\s*%?\s*$/', $value, $match)) {
        return (float) $match[1];
    }
    return null;
}

function normalize_assessment_grading_system(mixed $value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $value = $decoded;
        } else {
            $gradingText = $value;
            $value = [];
            foreach (['lecture' => 'lectureWeight', 'laboratory' => 'labWeight', 'lab' => 'labWeight'] as $label => $field) {
                if (preg_match('/\b' . $label . '\b[^0-9]{0,30}(\d+(?:\.\d+)?)\s*%/i', $gradingText, $match)) {
                    $value[$field] = (float) $match[1];
                }
            }
        }
    }
    if (!is_array($value)) {
        $value = [];
    }
    $components = $value['components'] ?? [];
    if (is_string($components)) {
        $components = syllabus_string_list($components);
    }
    $normalizedComponents = [];
    foreach (is_array($components) ? $components : [] as $component) {
        if (is_string($component)) {
            if (preg_match('/^(.*?)\s*[:–-]\s*(\d+(?:\.\d+)?)\s*%?$/u', trim($component), $match)) {
                $normalizedComponents[] = ['name' => trim($match[1]), 'percentage' => (float) $match[2]];
            }
            continue;
        }
        if (is_array($component)) {
            $name = trim((string) ($component['name'] ?? $component['component'] ?? ''));
            $percentage = syllabus_percentage($component['percentage'] ?? null);
            if ($name !== '' && $percentage !== null) {
                $normalizedComponents[] = ['name' => $name, 'percentage' => $percentage];
            }
        }
    }
    $lectureWeight = syllabus_percentage($value['lectureWeight'] ?? null);
    $labWeight = syllabus_percentage($value['labWeight'] ?? null);
    if ($lectureWeight === null || $lectureWeight < 0 || $lectureWeight > 100) {
        throw new AiGenerationException('The AI returned an invalid grading-weight structure. Generate the syllabus again; do not use an unverified grading policy.');
    }
    if ($labWeight !== null && ($labWeight < 0 || $labWeight > 100)) {
        throw new AiGenerationException('The AI returned an invalid laboratory grading weight. Generate the syllabus again; do not use an unverified grading policy.');
    }
    return [
        'lectureWeight' => $lectureWeight,
        'labWeight' => $labWeight,
        'components' => $normalizedComponents,
    ];
}

function normalize_generated_syllabus(array $result): array
{
    $result['qualityPolicy'] = syllabus_string_list($result['qualityPolicy'] ?? []);
    $result['institutionalOutcomes'] = normalize_syllabus_outcomes($result['institutionalOutcomes'] ?? [], 'IO', true);
    $result['graduateAttributes'] = normalize_syllabus_outcomes($result['graduateAttributes'] ?? [], 'GA', true);
    $result['programOutcomes'] = normalize_syllabus_outcomes($result['programOutcomes'] ?? [], 'PO', false);
    $result['courseLearningOutcomes'] = normalize_syllabus_clos($result['courseLearningOutcomes'] ?? []);
    $result['courseObjectives'] = syllabus_string_list($result['courseObjectives'] ?? []);
    $result['goalsOfCollege'] = syllabus_string_list($result['goalsOfCollege'] ?? []);
    $result['classroomPolicies'] = syllabus_string_list($result['classroomPolicies'] ?? []);
    $result['references'] = normalize_syllabus_references($result['references'] ?? []);
    $sdgs = $result['sdgs'] ?? [];
    if (is_array($sdgs) && (isset($sdgs['code']) || isset($sdgs['title']) || isset($sdgs['name']))) {
        $sdgs = [$sdgs];
    }
    $result['sdgs'] = array_values(array_filter(array_map(
        static function (mixed $sdg, int $index): ?array {
            if (is_string($sdg)) {
                return ['code' => 'SDG ' . ($index + 1), 'title' => trim($sdg), 'description' => ''];
            }
            if (!is_array($sdg)) {
                return null;
            }
            $title = trim((string) ($sdg['title'] ?? $sdg['name'] ?? $sdg['description'] ?? ''));
            if ($title === '') {
                return null;
            }
            return [
                'code' => trim((string) ($sdg['code'] ?? 'SDG ' . ($index + 1))),
                'title' => $title,
                'description' => trim((string) ($sdg['description'] ?? '')),
            ];
        },
        is_array($sdgs) ? array_values($sdgs) : [],
        array_keys(is_array($sdgs) ? array_values($sdgs) : [])
    )));
    $result['assessmentGradingSystem'] = normalize_assessment_grading_system($result['assessmentGradingSystem'] ?? []);
    $attainmentMatrix = $result['courseAssessmentAttainmentMatrix'] ?? [];
    if (is_string($attainmentMatrix)) {
        $decoded = json_decode($attainmentMatrix, true);
        $attainmentMatrix = is_array($decoded) ? $decoded : [];
    }
    if (is_array($attainmentMatrix) && isset($attainmentMatrix['cloCode'])) {
        $attainmentMatrix = [$attainmentMatrix];
    }
    $result['courseAssessmentAttainmentMatrix'] = array_values(array_filter(array_map(
        static function (mixed $item): ?array {
            if (!is_array($item)) {
                return null;
            }
            return [
                'cloCode' => trim((string) ($item['cloCode'] ?? '')),
                'assessmentEvidence' => trim((string) ($item['assessmentEvidence'] ?? '')),
                'measure' => trim((string) ($item['measure'] ?? 'Direct')),
                'performanceIndicator' => trim((string) ($item['performanceIndicator'] ?? '')),
                'rubricStandard' => trim((string) ($item['rubricStandard'] ?? '')),
                'target' => trim((string) ($item['target'] ?? '')),
                'type' => trim((string) ($item['type'] ?? '')),
            ];
        },
        is_array($attainmentMatrix) ? $attainmentMatrix : []
    )));
    $gradeEquivalents = $result['gradeEquivalents'] ?? [];
    if (is_array($gradeEquivalents) && isset($gradeEquivalents['range'])) {
        $gradeEquivalents = [$gradeEquivalents];
    }
    $result['gradeEquivalents'] = array_values(array_filter(array_map(
        static function (mixed $item): ?array {
            if (!is_array($item)) {
                return null;
            }
            $range = trim((string) ($item['range'] ?? ''));
            $grade = trim((string) ($item['grade'] ?? ''));
            return $range !== '' && $grade !== '' ? ['range' => $range, 'grade' => $grade] : null;
        },
        is_array($gradeEquivalents) ? $gradeEquivalents : []
    )));
    $aiPolicy = $result['aiPolicy'] ?? null;
    if (is_string($aiPolicy)) {
        $decoded = json_decode($aiPolicy, true);
        $aiPolicy = is_array($decoded) ? $decoded : null;
    }
    if (is_array($aiPolicy)) {
        $tiers = $aiPolicy['tiers'] ?? [];
        if (is_string($tiers)) {
            $tiers = syllabus_string_list($tiers);
        }
        $normalizedTiers = [];
        foreach (is_array($tiers) ? $tiers : [] as $tier) {
            if (is_string($tier)) {
                $normalizedTiers[] = ['tier' => '', 'description' => $tier];
            } elseif (is_array($tier)) {
                $description = trim((string) ($tier['description'] ?? $tier['text'] ?? ''));
                if ($description !== '') {
                    $normalizedTiers[] = [
                        'tier' => trim((string) ($tier['tier'] ?? $tier['name'] ?? '')),
                        'description' => $description,
                    ];
                }
            }
        }
        $result['aiPolicy'] = [
            'policyName' => trim((string) ($aiPolicy['policyName'] ?? $aiPolicy['name'] ?? 'AI use policy (faculty review required)')),
            'threshold' => trim((string) ($aiPolicy['threshold'] ?? '')),
            'tiers' => $normalizedTiers,
        ];
    } else {
        $result['aiPolicy'] = null;
    }
    $signatories = $result['signatories'] ?? [];
    if (!is_array($signatories)) {
        $signatories = [];
    }
    $result['signatories'] = [
        'preparedBy' => trim((string) ($signatories['preparedBy'] ?? '')),
        'reviewedBy' => trim((string) ($signatories['reviewedBy'] ?? '')),
        'approvedBy' => trim((string) ($signatories['approvedBy'] ?? '')),
        'date' => trim((string) ($signatories['date'] ?? '')),
    ];
    $result['coursePlan'] = is_array($result['coursePlan'] ?? null) ? array_values($result['coursePlan']) : [];
    foreach ($result['coursePlan'] as $index => $week) {
        if (!is_array($week)) {
            continue;
        }
        $week['lilos'] = syllabus_string_list($week['lilos'] ?? []);
        $week['cloCodes'] = syllabus_string_list($week['cloCodes'] ?? []);
        $week['ats'] = syllabus_string_list($week['ats'] ?? []);
        $week['resources'] = syllabus_string_list($week['resources'] ?? []);
        $activities = $week['tlas'] ?? [];
        if (is_string($activities)) {
            $activities = syllabus_string_list($activities);
        }
        $week['tlas'] = [];
        foreach (is_array($activities) ? $activities : [] as $activity) {
            if (is_string($activity)) {
                $week['tlas'][] = ['modality' => 'As specified by instructor', 'activity' => trim($activity)];
            } elseif (is_array($activity)) {
                $text = trim((string) ($activity['activity'] ?? $activity['description'] ?? $activity['name'] ?? ''));
                if ($text !== '') {
                    $week['tlas'][] = [
                        'modality' => trim((string) ($activity['modality'] ?? 'As specified by instructor')),
                        'activity' => $text,
                    ];
                }
            }
        }
        $result['coursePlan'][$index] = $week;
    }
    return $result;
}

function validate_generated_syllabus(array $result, array $request): array
{
    $result = normalize_generated_syllabus($result);
    $requiredArrays = [
        'institutionalOutcomes',
        'graduateAttributes',
        'programOutcomes',
        'courseLearningOutcomes',
        'courseObjectives',
        'coursePlan',
        'references',
    ];
    $header = $result['headerInfo'] ?? null;
    if (!is_array($header)) {
        throw new AiGenerationException('The AI response is missing syllabus course and program information. Try again.');
    }
    foreach ($requiredArrays as $field) {
        if (!is_array($result[$field] ?? null)) {
            throw new AiGenerationException('The AI response is missing required syllabus sections (' . $field . '). Try generating again.');
        }
    }
    if (count($result['coursePlan']) !== 18) {
        throw new AiGenerationException('The AI response did not contain all 18 course-plan weeks. Please generate again.');
    }
    if (count($result['courseLearningOutcomes']) === 0 || count($result['programOutcomes']) === 0) {
        throw new AiGenerationException('The AI response did not include program outcomes and mapped course learning outcomes. Please generate again.');
    }
    foreach ($result['coursePlan'] as $index => $week) {
        if (is_array($week) && is_string($week['lilos'] ?? null)) {
            $week['lilos'] = array_values(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/u', $week['lilos']) ?: []),
                static fn (string $item): bool => $item !== ''
            ));
            if (!$week['lilos']) {
                $week['lilos'] = [trim((string) $result['coursePlan'][$index]['lilos'])];
            }
            $result['coursePlan'][$index] = $week;
        }
        if (
            !is_array($week)
            || !is_string($week['topic'] ?? null) || trim($week['topic']) === ''
            || !is_array($week['lilos'] ?? null)
            || !is_array($week['cloCodes'] ?? null)
            || !is_array($week['tlas'] ?? null)
            || !is_array($week['ats'] ?? null)
            || !is_array($week['resources'] ?? null)
        ) {
            throw new AiGenerationException('The AI response has an incomplete course plan at week ' . ($index + 1) . '. Please generate again.');
        }
        $result['coursePlan'][$index]['weekNumber'] = $index + 1;
    }
    foreach (['courseCode', 'courseTitle', 'program', 'chedCmo', 'creditUnits', 'prerequisites'] as $field) {
        if (!is_string($header[$field] ?? null) || trim($header[$field]) === '') {
            throw new AiGenerationException('The AI response is missing syllabus header information (' . $field . '). Please generate again.');
        }
    }

    foreach ([
        'courseCode' => 'courseCode',
        'courseTitle' => 'courseTitle',
        'program' => 'program',
        'creditUnits' => 'creditUnits',
        'prerequisites' => 'prerequisites',
        'college' => 'college',
        'department' => 'department',
        'semester' => 'semester',
        'academicYear' => 'academicYear',
        'instructorName' => 'instructorName',
    ] as $headerField => $requestField) {
        if (isset($request[$requestField]) && is_string($request[$requestField])) {
            $header[$headerField] = $request[$requestField];
        }
    }
    $result['headerInfo'] = $header;
    return $result;
}

function upload_directory(): string
{
    $configured = trim((string) config_value('UPLOAD_STORAGE_PATH', ''));
    $directory = $configured !== ''
        ? $configured
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'faculty-workload-private-uploads';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Private upload storage could not be initialized.');
    }
    return $directory;
}

function ensure_active_semester(PDO $pdo, array $profile): array
{
    $active = $pdo->query('SELECT id, name, start_date, end_date, is_active FROM semesters WHERE is_active = 1 LIMIT 1')->fetch();
    if ($active) {
        return $active;
    }

    $academicYear = trim((string) ($profile['academicYear'] ?? ''));
    if (!preg_match('/^(\d{4})\D+(\d{4})$/', $academicYear, $years)) {
        $startYear = (int) gmdate('Y');
        $academicYear = $startYear . '-' . ($startYear + 1);
        $years = [0 => '', 1 => (string) $startYear, 2 => (string) ($startYear + 1)];
    }
    $semester = trim((string) ($profile['semester'] ?? '1st Semester')) ?: '1st Semester';
    $semesterName = $semester . ' A.Y. ' . $academicYear;
    $firstYear = (int) $years[1];
    $secondYear = (int) $years[2];
    if (str_contains(strtolower($semester), '2')) {
        $startDate = sprintf('%04d-01-01', $secondYear);
        $endDate = sprintf('%04d-05-31', $secondYear);
    } elseif (str_contains(strtolower($semester), 'summer') || str_contains(strtolower($semester), 'midyear')) {
        $startDate = sprintf('%04d-06-01', $secondYear);
        $endDate = sprintf('%04d-07-31', $secondYear);
    } else {
        $startDate = sprintf('%04d-08-01', $firstYear);
        $endDate = sprintf('%04d-12-31', $firstYear);
    }

    $pdo->beginTransaction();
    try {
        $existing = $pdo->prepare('SELECT id, name, start_date, end_date, is_active FROM semesters WHERE name = ? LIMIT 1 FOR UPDATE');
        $existing->execute([$semesterName]);
        $row = $existing->fetch();
        if ($row) {
            $activate = $pdo->prepare('UPDATE semesters SET is_active = 0');
            $activate->execute();
            $activate = $pdo->prepare('UPDATE semesters SET is_active = 1 WHERE id = ?');
            $activate->execute([$row['id']]);
            $row['is_active'] = 1;
        } else {
            $row = [
                'id' => bin2hex(random_bytes(16)),
                'name' => $semesterName,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_active' => 1,
            ];
            $pdo->prepare('UPDATE semesters SET is_active = 0')->execute();
            $insert = $pdo->prepare('INSERT INTO semesters (id, name, start_date, end_date, is_active) VALUES (?, ?, ?, ?, 1)');
            $insert->execute([$row['id'], $row['name'], $row['start_date'], $row['end_date']]);
        }
        $pdo->commit();
        return $row;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $active = $pdo->query('SELECT id, name, start_date, end_date, is_active FROM semesters WHERE is_active = 1 LIMIT 1')->fetch();
        if ($active) {
            return $active;
        }
        throw $error;
    }
}

function extract_document(string $type, string $path, string $extension): array
{
    $node = (string) config_value('NODE_BINARY', 'node');
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'extract-document.mjs';
    $process = proc_open(
        [$node, $script, $type, $path, $extension],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        __DIR__,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Document extraction could not be started. Confirm Node.js and the project dependencies are installed.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || !is_string($output)) {
        error_log('Document extraction process failed: ' . trim((string) $errors));
        throw new InvalidArgumentException(
            trim((string) $errors) ?: 'The document could not be extracted. Confirm it is a text-based PDF or valid DOCX.'
        );
    }
    $result = json_decode($output, true);
    if (!is_array($result)) {
        throw new RuntimeException('Document extraction returned invalid data.');
    }
    return $result;
}

function validate_upload(string $type): array
{
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['name'], $file['size'])) {
        $postLimit = (string) ini_get('post_max_size');
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > ini_size_bytes($postLimit)) {
            throw new InvalidArgumentException('The upload exceeds the PHP post_max_size limit. Configure post_max_size above 15 MB and retry.');
        }
        throw new InvalidArgumentException('Choose a file to upload.');
    }
    if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        $message = match ((int) $file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeds the server upload size limit.',
            UPLOAD_ERR_PARTIAL => 'The file upload was incomplete. Please retry.',
            UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
            default => 'The file could not be uploaded. Please retry.',
        };
        throw new InvalidArgumentException($message);
    }
    $extension = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $extension = '.' . $extension;
    $allowed = $type === 'schedule' ? ['.pdf'] : ['.pdf', '.docx'];
    if (!in_array($extension, $allowed, true)) {
        throw new InvalidArgumentException($type === 'schedule'
            ? 'Class schedules must be uploaded as PDF files.'
            : 'Syllabi must be uploaded as PDF or DOCX files.');
    }
    $size = (int) $file['size'];
    if ($size < 1 || $size > 15 * 1024 * 1024) {
        throw new InvalidArgumentException('Files must be smaller than 15 MB.');
    }
    $header = file_get_contents((string) $file['tmp_name'], false, null, 0, 8);
    $valid = $extension === '.pdf'
        ? is_string($header) && str_starts_with($header, '%PDF-')
        : is_string($header) && str_starts_with($header, "PK\x03\x04");
    if (!$valid) {
        throw new InvalidArgumentException('The file contents do not match the selected document type.');
    }
    $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', basename((string) $file['name'])) ?: 'upload' . $extension;
    return [
        'tmpName' => (string) $file['tmp_name'],
        'extension' => $extension,
        'size' => $size,
        'originalName' => function_exists('mb_substr') ? mb_substr($originalName, 0, 255) : substr($originalName, 0, 255),
        'contentType' => $extension === '.pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
}

function ini_size_bytes(string $value): int
{
    if (!preg_match('/^\s*(\d+)\s*([KMG])?B?\s*$/i', $value, $match)) {
        return PHP_INT_MAX;
    }
    $size = (int) $match[1];
    if ($size === 0) {
        return PHP_INT_MAX;
    }
    $unit = strtoupper($match[2] ?? '');
    return $size * match ($unit) {
        'K' => 1024,
        'M' => 1024 * 1024,
        'G' => 1024 * 1024 * 1024,
        default => 1,
    };
}

function remove_orphaned_uploads(PDO $pdo, array $candidates): array
{
    $remove = [];
    foreach ($candidates as $candidate) {
        $statement = $pdo->prepare(
            "SELECT f.id, f.storage_name FROM uploaded_files f
             WHERE f.id = ?
               AND NOT EXISTS (SELECT 1 FROM schedules s WHERE s.raw_file_url = CONCAT('/api/uploads/', f.id))
               AND NOT EXISTS (SELECT 1 FROM syllabi y WHERE y.raw_file_url = CONCAT('/api/uploads/', f.id))"
        );
        $statement->execute([$candidate]);
        $file = $statement->fetch();
        if ($file) {
            $pdo->prepare('DELETE FROM uploaded_files WHERE id = ?')->execute([$file['id']]);
            $remove[] = $file['storage_name'];
        }
    }
    return $remove;
}

try {
    $pdo = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = trim((string) ($_GET['path'] ?? ''), '/');
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $body = str_starts_with($contentType, 'multipart/form-data') ? [] : request_body();

    if ($method === 'GET' && $path === 'health') {
        respond(200, [
            'status' => 'ok',
            'hasApiKey' => (string) config_value('GEMINI_API_KEY', '') !== '',
            'hasSyllabusAiConfig' => (string) config_value('GEMINI_API_KEY', '') !== ''
                && (string) config_value('TAVILY_API_KEY', '') !== '',
        ]);
    }

    if ($method === 'GET' && $path === 'demo-accounts') {
        seed_demo_accounts($pdo);
        $visible = filter_var(config_value('SHOW_DEMO_CREDENTIALS', false), FILTER_VALIDATE_BOOLEAN);
        $accounts = [];
        foreach (demo_accounts() as $account) {
            if ($account['email'] === '' || $account['password'] === '') {
                continue;
            }
            $entry = ['role' => $account['role'], 'username' => $account['email'], 'displayName' => $account['name']];
            if ($visible) {
                $entry['password'] = $account['password'];
            }
            $accounts[] = $entry;
        }
        respond(200, ['success' => true, 'showCredentials' => $visible, 'accounts' => $accounts]);
    }

    if ($method === 'POST' && $path === 'auth/login') {
        seed_demo_accounts($pdo);
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $find = $pdo->prepare('SELECT id, email, role, password_hash, profile_json FROM users WHERE email = ? LIMIT 1');
        $find->execute([$email]);
        $record = $find->fetch();
        if (!$record || !password_verify($password, $record['password_hash'])) {
            respond(401, ['success' => false, 'error' => 'The username or password is incorrect.']);
        }
        $profile = json_decode($record['profile_json'], true);
        if (!is_array($profile)) {
            throw new RuntimeException('Stored account profile is invalid.');
        }
        start_auth($pdo, $record['id']);
        respond(200, auth_response($pdo, ['id' => $record['id'], 'email' => $record['email'], 'role' => $record['role'], 'profile' => $profile]));
    }

    if ($method === 'POST' && $path === 'auth/register') {
        if (!filter_var(config_value('ALLOW_FACULTY_REGISTRATION', true), FILTER_VALIDATE_BOOLEAN)) {
            respond(403, ['success' => false, 'error' => 'Faculty registration is disabled by the system administrator.']);
        }
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $profile = $body['profile'] ?? null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || !is_array($profile)) {
            respond(422, ['success' => false, 'error' => 'Enter a valid institutional email, a password with at least 12 characters, and a complete profile.']);
        }
        $profile['email'] = $email;
        $userId = bin2hex(random_bytes(16));
        try {
            $insert = $pdo->prepare('INSERT INTO users (id, email, password_hash, role, profile_json) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$userId, $email, password_hash($password, PASSWORD_DEFAULT), 'faculty', json_string($profile)]);
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                respond(409, ['success' => false, 'error' => 'An account with this email already exists.']);
            }
            throw $error;
        }
        start_auth($pdo, $userId);
        respond(201, auth_response($pdo, ['id' => $userId, 'email' => $email, 'role' => 'faculty', 'profile' => $profile]));
    }

    if ($method === 'GET' && $path === 'auth/session') {
        $user = current_user($pdo);
        if (!$user) {
            respond(401, ['success' => false, 'error' => 'Not signed in.']);
        }
        respond(200, auth_response($pdo, $user));
    }

    if ($method === 'POST' && $path === 'auth/logout') {
        $token = $_COOKIE['fwp_session'] ?? '';
        if (is_string($token) && $token !== '') {
            $delete = $pdo->prepare('DELETE FROM auth_sessions WHERE token_hash = ?');
            $delete->execute([hash('sha256', $token)]);
        }
        set_auth_cookie('', time() - 3600);
        respond(200, ['success' => true]);
    }

    $user = current_user($pdo);
    if (!$user) {
        respond(401, ['success' => false, 'error' => 'Sign in is required.']);
    }

    if ($method === 'GET' && $path === 'semesters') {
        if ($user['role'] !== 'faculty') {
            respond(403, ['success' => false, 'error' => 'Faculty access is required.']);
        }
        ensure_active_semester($pdo, $user['profile']);
        $semesters = $pdo->query(
            'SELECT id, name, start_date, end_date, is_active FROM semesters ORDER BY start_date DESC, name'
        )->fetchAll();
        foreach ($semesters as &$semester) {
            $semester['is_active'] = (bool) $semester['is_active'];
        }
        unset($semester);
        respond(200, ['success' => true, 'semesters' => $semesters]);
    }

    if ($method === 'POST' && $path === 'semesters') {
        if ($user['role'] !== 'faculty') {
            respond(403, ['success' => false, 'error' => 'Faculty access is required.']);
        }
        $name = trim((string) ($body['name'] ?? ''));
        $startDate = trim((string) ($body['startDate'] ?? ''));
        $endDate = trim((string) ($body['endDate'] ?? ''));
        if ($name === '' || strlen($name) > 120) {
            throw new InvalidArgumentException('Enter a semester name no longer than 120 characters.');
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        $startErrors = DateTimeImmutable::getLastErrors();
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
        $endErrors = DateTimeImmutable::getLastErrors();
        if (
            !$start || $start->format('Y-m-d') !== $startDate
            || ($startErrors && ($startErrors['warning_count'] > 0 || $startErrors['error_count'] > 0))
            || !$end || $end->format('Y-m-d') !== $endDate
            || ($endErrors && ($endErrors['warning_count'] > 0 || $endErrors['error_count'] > 0))
        ) {
            throw new InvalidArgumentException('Enter valid start and end dates for the semester.');
        }
        if ($end < $start) {
            throw new InvalidArgumentException('The semester end date must be on or after its start date.');
        }
        $semester = [
            'id' => bin2hex(random_bytes(16)),
            'name' => $name,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_active' => false,
        ];
        try {
            $create = $pdo->prepare(
                'INSERT INTO semesters (id, name, start_date, end_date, is_active) VALUES (?, ?, ?, ?, 0)'
            );
            $create->execute([
                $semester['id'],
                $semester['name'],
                $semester['start_date'],
                $semester['end_date'],
            ]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new InvalidArgumentException('A semester with that name already exists.');
            }
            throw $error;
        }
        respond(201, ['success' => true, 'semester' => $semester]);
    }

    if ($method === 'GET' && preg_match('#^semesters/([a-f0-9]{32})/records$#i', $path, $match)) {
        if ($user['role'] !== 'faculty') {
            respond(403, ['success' => false, 'error' => 'Faculty access is required.']);
        }
        $semesterId = strtolower($match[1]);
        $semester = $pdo->prepare('SELECT id FROM semesters WHERE id = ? LIMIT 1');
        $semester->execute([$semesterId]);
        if (!$semester->fetch()) {
            respond(404, ['success' => false, 'error' => 'The selected semester was not found.']);
        }
        $scheduleQuery = $pdo->prepare(
            "SELECT s.id, s.course_code AS courseCode, s.course_title AS courseTitle,
                    s.section, s.day, s.time_start AS timeStart, s.time_end AS timeEnd,
                    s.raw_file_url AS rawFileUrl, f.original_filename AS fileName
             FROM schedules s
             LEFT JOIN uploaded_files f ON s.raw_file_url = CONCAT('/api/uploads/', f.id)
             WHERE s.faculty_id = ? AND s.semester_id = ?
             ORDER BY FIELD(s.day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
                      s.time_start, s.course_code"
        );
        $scheduleQuery->execute([$user['id'], $semesterId]);
        $syllabusQuery = $pdo->prepare(
            "SELECT y.id, y.course_code AS courseCode, y.course_title AS courseTitle,
                    y.extracted_json_data AS extractedJsonData, y.raw_file_url AS rawFileUrl,
                    f.original_filename AS fileName, y.updated_at AS updatedAt
             FROM syllabi y
             LEFT JOIN uploaded_files f ON y.raw_file_url = CONCAT('/api/uploads/', f.id)
             WHERE y.faculty_id = ? AND y.semester_id = ?
             ORDER BY y.course_code"
        );
        $syllabusQuery->execute([$user['id'], $semesterId]);
        $syllabi = [];
        foreach ($syllabusQuery->fetchAll() as $row) {
            $extracted = json_decode($row['extractedJsonData'], true);
            if (!is_array($extracted)) {
                throw new RuntimeException('Stored extracted syllabus data is invalid.');
            }
            if (isset($extracted['text']) && is_string($extracted['text']) && strlen($extracted['text']) > 2000) {
                $extracted['text'] = substr($extracted['text'], 0, 2000) . "\n[Preview truncated. Open the source file for the complete document.]";
            }
            $row['extractedJsonData'] = $extracted;
            $syllabi[] = $row;
        }
        respond(200, [
            'success' => true,
            'schedule' => $scheduleQuery->fetchAll(),
            'syllabi' => $syllabi,
        ]);
    }

    if ($method === 'GET' && preg_match('#^uploads/([a-f0-9]{32})$#i', $path, $match)) {
        $fileQuery = $pdo->prepare(
            'SELECT storage_name, original_filename, content_type
             FROM uploaded_files
             WHERE id = ? AND (faculty_id = ? OR ? = ?)
             LIMIT 1'
        );
        $fileQuery->execute([strtolower($match[1]), $user['id'], $user['role'], 'admin']);
        $file = $fileQuery->fetch();
        if (!$file) {
            respond(404, ['success' => false, 'error' => 'Uploaded file not found.']);
        }
        $storageName = basename($file['storage_name']);
        $filePath = upload_directory() . DIRECTORY_SEPARATOR . $storageName;
        if (!is_file($filePath)) {
            respond(404, ['success' => false, 'error' => 'The stored source file is unavailable.']);
        }
        header('Content-Type: ' . $file['content_type']);
        header('Content-Length: ' . (string) filesize($filePath));
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $file['original_filename']) . '"; filename*=UTF-8\'\'' . rawurlencode($file['original_filename']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        readfile($filePath);
        exit;
    }

    if ($method === 'POST' && in_array($path, ['upload/schedule', 'upload/syllabus'], true)) {
        if ($user['role'] !== 'faculty') {
            respond(403, ['success' => false, 'error' => 'Faculty access is required.']);
        }
        $type = $path === 'upload/schedule' ? 'schedule' : 'syllabus';
        $file = validate_upload($type);
        $semesterId = strtolower(trim((string) ($_POST['semesterId'] ?? '')));
        if (!preg_match('/^[a-f0-9]{32}$/', $semesterId)) {
            throw new InvalidArgumentException('Choose a valid target semester.');
        }
        $semesterQuery = $pdo->prepare('SELECT id, is_active FROM semesters WHERE id = ? LIMIT 1');
        $semesterQuery->execute([$semesterId]);
        $semester = $semesterQuery->fetch();
        if (!$semester) {
            throw new InvalidArgumentException('The selected semester was not found. Refresh the page and try again.');
        }

        $extracted = extract_document($type, $file['tmpName'], $file['extension']);
        $records = [];
        $courseCode = '';
        $courseTitle = '';
        if ($type === 'schedule') {
            $records = $extracted['records'] ?? [];
            if (!is_array($records) || count($records) === 0) {
                throw new InvalidArgumentException('No schedule rows were recognized. Use a text-based PDF with each class showing its course code, day, and start/end times.');
            }
            if (count($records) > 500) {
                throw new InvalidArgumentException('The uploaded schedule contains too many rows to process (maximum 500).');
            }
        } else {
            $courseCode = strtoupper(trim((string) ($_POST['courseCode'] ?? $extracted['courseCode'] ?? '')));
            $courseCode = preg_replace('/\s+/', ' ', $courseCode) ?: '';
            $courseTitle = trim((string) ($extracted['courseTitle'] ?? ''));
            if ($courseCode === '' || !preg_match('/^[A-Z0-9][A-Z0-9 .&()\/-]{0,79}$/', $courseCode)) {
                throw new InvalidArgumentException('Could not identify a valid course code. Enter the course code before uploading again.');
            }
            if (strlen($courseTitle) > 255) {
                $courseTitle = substr($courseTitle, 0, 255);
            }
            if (!is_string($extracted['text'] ?? null)) {
                throw new InvalidArgumentException('The syllabus text could not be extracted.');
            }
        }

        $storageDirectory = upload_directory();
        $fileId = bin2hex(random_bytes(16));
        $storageName = $fileId . $file['extension'];
        $storagePath = $storageDirectory . DIRECTORY_SEPARATOR . $storageName;
        if (!move_uploaded_file($file['tmpName'], $storagePath)) {
            throw new RuntimeException('The uploaded file could not be stored securely.');
        }
        $rawFileUrl = '/api/uploads/' . $fileId;
        $oldFileIds = [];
        $removeFiles = [];
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id FROM semesters WHERE id = ? FOR UPDATE');
            $lock->execute([$semesterId]);
            if (!$lock->fetch()) {
                throw new InvalidArgumentException('The selected semester is no longer available.');
            }
            $fileInsert = $pdo->prepare(
                'INSERT INTO uploaded_files
                 (id, faculty_id, semester_id, document_type, original_filename, storage_name, content_type, byte_size)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $fileInsert->execute([
                $fileId, $user['id'], $semesterId, $type, $file['originalName'],
                $storageName, $file['contentType'], $file['size'],
            ]);

            if ($type === 'schedule') {
                $oldUploads = $pdo->prepare(
                    "SELECT id FROM uploaded_files WHERE faculty_id = ? AND semester_id = ? AND document_type = 'schedule'"
                );
                $oldUploads->execute([$user['id'], $semesterId]);
                $oldFileIds = array_column($oldUploads->fetchAll(), 'id');
                $pdo->prepare('DELETE FROM schedules WHERE faculty_id = ? AND semester_id = ?')
                    ->execute([$user['id'], $semesterId]);
                $insertSchedule = $pdo->prepare(
                    'INSERT INTO schedules
                     (id, faculty_id, semester_id, course_code, course_title, section, day, time_start, time_end, raw_file_url)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $workspaceSchedule = [];
                $seen = [];
                foreach ($records as $record) {
                    if (!is_array($record)) {
                        continue;
                    }
                    $code = strtoupper(trim((string) ($record['courseCode'] ?? '')));
                    $day = trim((string) ($record['day'] ?? ''));
                    $timeStart = (string) ($record['timeStart'] ?? '');
                    $timeEnd = (string) ($record['timeEnd'] ?? '');
                    $section = trim((string) ($record['section'] ?? ''));
                    $courseTitle = trim((string) ($record['courseTitle'] ?? ''));
                    if (
                        $code === '' || strlen($code) > 80
                        || !in_array($day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], true)
                        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:00$/', $timeStart)
                        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:00$/', $timeEnd)
                        || $timeStart >= $timeEnd
                    ) {
                        continue;
                    }
                    $key = implode('|', [$code, $section, $day, $timeStart, $timeEnd]);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $id = bin2hex(random_bytes(16));
                    $insertSchedule->execute([
                        $id, $user['id'], $semesterId, $code, substr($courseTitle, 0, 255),
                        substr($section, 0, 120), $day, $timeStart, $timeEnd, $rawFileUrl,
                    ]);
                    $workspaceSchedule[] = [
                        'id' => $id,
                        'courseCode' => $code,
                        'courseTitle' => substr($courseTitle, 0, 255),
                        'section' => substr($section, 0, 120),
                        'dayOfWeek' => $day,
                        'startTime' => date('h:i A', strtotime($timeStart)),
                        'endTime' => date('h:i A', strtotime($timeEnd)),
                        'creditUnits' => 0,
                        'roomOrPlatform' => 'Imported from schedule PDF',
                    ];
                }
                if (!$workspaceSchedule) {
                    throw new InvalidArgumentException('No valid course, day, and time rows were found in the schedule PDF.');
                }
                if ((bool) $semester['is_active']) {
                    $save = $pdo->prepare(
                        "INSERT INTO user_data (user_id, data_key, data_json) VALUES (?, 'schedule', ?)
                         ON DUPLICATE KEY UPDATE data_json = VALUES(data_json)"
                    );
                    $save->execute([$user['id'], json_string($workspaceSchedule)]);
                }
            } else {
                $previous = $pdo->prepare(
                    'SELECT raw_file_url FROM syllabi WHERE faculty_id = ? AND semester_id = ? AND course_code = ? LIMIT 1 FOR UPDATE'
                );
                $previous->execute([$user['id'], $semesterId, $courseCode]);
                $previousUrl = $previous->fetchColumn();
                if (is_string($previousUrl) && preg_match('#^/api/uploads/([a-f0-9]{32})$#i', $previousUrl, $oldMatch)) {
                    $oldFileIds[] = strtolower($oldMatch[1]);
                }
                $syllabus = [
                    'courseCode' => $courseCode,
                    'courseTitle' => $courseTitle,
                    'text' => $extracted['text'],
                    'tables' => is_array($extracted['tables'] ?? null) ? $extracted['tables'] : [],
                ];
                $saveSyllabus = $pdo->prepare(
                    'INSERT INTO syllabi
                     (id, faculty_id, semester_id, course_code, course_title, extracted_json_data, raw_file_url)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE id = VALUES(id), course_title = VALUES(course_title),
                       extracted_json_data = VALUES(extracted_json_data), raw_file_url = VALUES(raw_file_url)'
                );
                $saveSyllabus->execute([
                    bin2hex(random_bytes(16)), $user['id'], $semesterId, $courseCode, $courseTitle,
                    json_string($syllabus), $rawFileUrl,
                ]);
            }
            $removeFiles = remove_orphaned_uploads($pdo, array_unique($oldFileIds));
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (is_file($storagePath)) {
                unlink($storagePath);
            }
            throw $error;
        }
        foreach ($removeFiles as $oldStorageName) {
            $oldPath = $storageDirectory . DIRECTORY_SEPARATOR . basename($oldStorageName);
            if (is_file($oldPath)) {
                unlink($oldPath);
            }
        }
        respond(200, [
            'success' => true,
            'message' => $type === 'schedule'
                ? 'Schedule extracted and saved for the selected semester.'
                : 'Syllabus extracted and saved for the selected semester.',
            'fileName' => $file['originalName'],
            'rawFileUrl' => $rawFileUrl,
            'recordCount' => $type === 'schedule' ? count($workspaceSchedule) : 1,
            'schedule' => $type === 'schedule' ? $workspaceSchedule : null,
        ]);
    }

    if ($method === 'GET' && $path === 'workspace') {
        respond(200, auth_response($pdo, $user));
    }

    if ($method === 'PUT' && $path === 'workspace') {
        if (strlen(json_string($body)) > 12 * 1024 * 1024) {
            respond(413, ['success' => false, 'error' => 'Workspace exceeds the 12 MB request limit.']);
        }
        save_workspace($pdo, $user['id'], $body);
        respond(200, ['success' => true, 'message' => 'Workspace saved.']);
    }

    if ($method === 'GET' && $path === 'admin/faculties') {
        if ($user['role'] !== 'admin') {
            respond(403, ['success' => false, 'error' => 'Administrator access is required.']);
        }
        $statement = $pdo->query(
            "SELECT u.id, u.email, u.profile_json, d.data_json AS syllabi_json,
                    (SELECT COUNT(*) FROM syllabi y WHERE y.faculty_id = u.id) AS uploaded_syllabus_count
             FROM users u
             LEFT JOIN user_data d ON d.user_id = u.id AND d.data_key = 'syllabi'
             WHERE u.role = 'faculty'
             ORDER BY u.email"
        );
        $faculties = [];
        foreach ($statement->fetchAll() as $row) {
            $profile = json_decode($row['profile_json'], true);
            $syllabi = $row['syllabi_json'] === null ? [] : json_decode($row['syllabi_json'], true);
            if (!is_array($profile) || !is_array($syllabi)) {
                throw new RuntimeException('Faculty profile or syllabus data is invalid.');
            }
            $profile['email'] = $row['email'];
            $faculties[] = [
                'id' => $row['id'],
                'email' => $row['email'],
                'profile' => $profile,
                'syllabusCount' => count($syllabi) + (int) $row['uploaded_syllabus_count'],
            ];
        }
        respond(200, ['success' => true, 'count' => count($faculties), 'data' => $faculties]);
    }

    if ($method === 'GET' && preg_match('#^admin/faculties/([a-f0-9-]{32,36})$#i', $path, $match)) {
        if ($user['role'] !== 'admin') {
            respond(403, ['success' => false, 'error' => 'Administrator access is required.']);
        }
        $statement = $pdo->prepare(
            "SELECT u.email, u.profile_json, d.data_json AS syllabi_json
             FROM users u
             LEFT JOIN user_data d ON d.user_id = u.id AND d.data_key = 'syllabi'
             WHERE u.id = ? AND u.role = 'faculty'
             LIMIT 1"
        );
        $statement->execute([$match[1]]);
        $row = $statement->fetch();
        if (!$row) {
            respond(404, ['success' => false, 'error' => 'Faculty account not found.']);
        }
        $profile = json_decode($row['profile_json'], true);
        $syllabi = $row['syllabi_json'] === null ? [] : json_decode($row['syllabi_json'], true);
        if (!is_array($profile) || !is_array($syllabi)) {
            throw new RuntimeException('Faculty profile or syllabus data is invalid.');
        }
        $profile['email'] = $row['email'];
        $uploadedQuery = $pdo->prepare(
            "SELECT y.id, y.course_code AS courseCode, y.course_title AS courseTitle,
                    y.extracted_json_data AS extractedJsonData, y.raw_file_url AS rawFileUrl,
                    f.original_filename AS fileName, sem.name AS semesterName, y.updated_at AS updatedAt
             FROM syllabi y
             JOIN semesters sem ON sem.id = y.semester_id
             LEFT JOIN uploaded_files f ON y.raw_file_url = CONCAT('/api/uploads/', f.id)
             WHERE y.faculty_id = ?
             ORDER BY sem.start_date DESC, y.course_code"
        );
        $uploadedQuery->execute([$match[1]]);
        $uploadedSyllabi = [];
        foreach ($uploadedQuery->fetchAll() as $uploaded) {
            $extracted = json_decode($uploaded['extractedJsonData'], true);
            if (!is_array($extracted)) {
                throw new RuntimeException('Stored extracted syllabus data is invalid.');
            }
            if (isset($extracted['text']) && is_string($extracted['text']) && strlen($extracted['text']) > 2000) {
                $extracted['text'] = substr($extracted['text'], 0, 2000) . "\n[Preview truncated. Open the source file for the complete document.]";
            }
            $uploaded['extractedJsonData'] = $extracted;
            $uploadedSyllabi[] = $uploaded;
        }
        respond(200, [
            'success' => true,
            'data' => ['profile' => $profile, 'syllabi' => $syllabi, 'uploadedSyllabi' => $uploadedSyllabi],
        ]);
    }

    if ($method === 'GET' && $path === 'admin/schedule-datasets') {
        if ($user['role'] !== 'admin') {
            respond(403, ['success' => false, 'error' => 'Administrator access is required.']);
        }
        seed_schedule_catalog($pdo);
        $rows = $pdo->query(
            'SELECT id, course_code AS courseCode, course_title AS courseTitle, section,
                    day_of_week AS dayOfWeek, start_time AS startTime, end_time AS endTime,
                    credit_units AS creditUnits, room_or_platform AS roomOrPlatform,
                    modality_default AS modalityDefault
             FROM schedule_datasets ORDER BY day_of_week, start_time, course_code'
        )->fetchAll();
        respond(200, ['success' => true, 'count' => count($rows), 'data' => $rows]);
    }

    if ($method === 'POST' && preg_match('#^generate/(syllabus|accomplishment-report|learning-activity-report|refine)$#', $path, $match)) {
        if (!function_exists('set_time_limit')) {
            throw new AiGenerationException('The PHP host does not allow AI requests to extend the execution time. Configure max_execution_time to at least 240 seconds.', 503);
        }
        set_time_limit(240);
        http_response_code(500);
        $generated = call_gemini($body, $match[1]);
        respond(200, ['success' => true, 'data' => $generated]);
    }

    respond(404, ['success' => false, 'error' => 'API route not found.']);
} catch (AiGenerationException $error) {
    respond($error->httpStatus, ['success' => false, 'error' => $error->getMessage()]);
} catch (InvalidArgumentException $error) {
    respond(422, ['success' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Faculty Workload API error: ' . $error->getMessage());
    $sqlErrorCode = $error instanceof PDOException ? (int) ($error->errorInfo[1] ?? 0) : 0;
    $missingSemesterSchema = $sqlErrorCode === 1142 || $sqlErrorCode === 1146 || $error->getCode() === '42S02';
    respond($missingSemesterSchema ? 503 : 500, [
        'success' => false,
        'error' => $missingSemesterSchema
            ? 'Semester history is not set up in MySQL yet. Ask the database administrator to apply database/migrations/001_semester_uploads.sql and grant the app account access to the semester tables.'
            : (filter_var(config_value('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN)
                ? $error->getMessage()
                : 'The server could not complete this request. Contact the system administrator if it continues.'),
    ]);
}
