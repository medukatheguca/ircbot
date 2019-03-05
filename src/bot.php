<?php

require_once __DIR__ . '/../vendor/autoload.php';

const DEBUG = true;

function t($str) {
	echo "\n\n\n\n" . $str . "\n\n\n\n";
}

class fish {
	public $img;
	public $name;
	public $val;
	public $cliqueable;

	public function __construct($i, $n, $v, $c) {
		$this->img = $i;
		$this->name = $n;
		$this->val = $v;
		$this->cliqueable = $c;
	}
}

class comm {

	private $list_of_genres;
	private $list_of_tags;

	private $iteration;
	private $max_page;
	private $format;
	private $genres;
	private $not_genres;
	private $tags;
	private $not_tags;
	private $isAdult;
	private $startDate_lesser;
	private $startDate_greater;

	private $empty_msg;

	# Page used for random search instead of id because id can lead to 404s
	const QUERY = '
	query ($format: MediaFormat, $page: Int, $id: Int, $genre_in: [String], $genre_not_in: [String], $tag_in: [String], $tag_not_in: [String], $isAdult: Boolean, $startDate_greater: FuzzyDateInt, $startDate_lesser: FuzzyDateInt) {
		Page(page: $page, perPage: 1) {
			media (format: $format, id: $id, genre_in: $genre_in, genre_not_in: $genre_not_in, tag_in: $tag_in, tag_not_in: $tag_not_in, isAdult: $isAdult, startDate_greater: $startDate_greater, startDate_lesser: $startDate_lesser) {
				id
				idMal
				format
				title {
					romaji
				}
				startDate {
					year
				}
				genres
				tags {
					name
				}
				episodes
				volumes
				relations {
					edges {
						relationType
						node {
							id
				            startDate {
				              year
				            }
						}
					}
				}
			}
		}
	}
	';

	# Used to find the max number of pages when the filters are applied
	const MAX_PAGES_QUERY = '
	query ($format: MediaFormat, $genre_in: [String], $genre_not_in: [String], $tag_in: [String], $tag_not_in: [String], $isAdult: Boolean, $startDate_greater: FuzzyDateInt, $startDate_lesser: FuzzyDateInt) {
		Page(perPage: 1) {
			pageInfo {
				total
			}
			media (format: $format, genre_in: $genre_in, genre_not_in: $genre_not_in, tag_in: $tag_in, tag_not_in: $tag_not_in, isAdult: $isAdult, startDate_greater: $startDate_greater, startDate_lesser: $startDate_lesser) {
				id
			}
		}
	}
	';

	const GENRES_TAGS_QUERY = '
	query {
		GenreCollection 
		MediaTagCollection {
			name
		}
	}
	';


	public function __construct() {

		self::safeState();
		$this->empty_msg = "{\"data\":{\"Page\":{\"media\":[]}}}";

		$variables = [];

		# Populate the list of genres
		$http = new GuzzleHttp\Client;
		$response = $http->post('https://graphql.anilist.co', [
			'json' => [
				'query' => self::GENRES_TAGS_QUERY,
				'variables' => $variables,
			]
		]);

		$g = (json_decode($response->getBody(), true))["data"]["GenreCollection"];
		$t = [];

		$tag_coll = (json_decode($response->getBody(), true))["data"]["MediaTagCollection"];
		for ($i = 0; $i < count($tag_coll); $i++) {
			array_push($t, $tag_coll[$i]["name"]);
		}

		self::split_words_into_array($this->list_of_genres, $g);
		self::split_words_into_array($this->list_of_tags, $t);

		var_dump($this->list_of_genres);
		var_dump($this->list_of_tags);
	}

	function safeState() {
		$this->iteration = 0;
		$this->max_page = 0;
		$this->format = "";
		$this->genres = [];
		$this->not_genres = [];
		$this->tags = [];
		$this->isAdult = false;
		$this->startDate_lesser = 0;
		$this->startDate_greater = 0;
	}

	# Begins a new iteration to find a recommendation using filters
	public function recommendation($format, $isAdult, $genres, $not_genres, $tags, $not_tags, $startDate_greater, $startDate_lesser) {
		
		self::safeState();
		$this->genres = $genres;
		$this->not_genres = $not_genres;
		$this->tags = $tags;
		$this->not_tags = $not_tags;
		$this->isAdult = $isAdult;
		$this->startDate_lesser = $startDate_lesser;
		$this->startDate_greater = $startDate_greater;

		# Find the max number of pages after all of the filters have been applied
		echo "Requesting max\n";
		$this->format = $format;
		if ($this->format == "ANIME") {
			$this->format = "TV";
		}
		else if ($this->format == "SHORT") {
			$this->format = "TV_SHORT";
		}
		
		self::request_max();
		echo "Max: " . $this->max_page . "\n";

		# Request a page until a non-empty recommendation is found or we have iterated 3 times
		# An empty rec will be found if the prequel/parent does not match the requested format type
		$body = $empty_msg;
		echo "Requesting page\n";
		do {
			echo "Iteration " . $this->iteration . "\n";
			$body = self::request_page()->getBody();
		} while ($body == $this->empty_msg && ++$this->iteration < 3);
		return $body;
	}

	public function get_genres() {
		return $this->list_of_genres;
	}

	public function get_tags() {
		return $this->list_of_tags;
	}

	public function get_id($json) {
		return (json_decode($json, true))["data"]["Page"]["media"][0]["id"];
	}

	public function get_year($json) {
		return (json_decode($json, true))["data"]["Page"]["media"][0]["startDate"]["year"];
	}

	function split_words_into_array(&$into, $from) {
		$counter = 0;

		foreach ($from as $element) {
			$e = explode(' ', $element);
			if (count($e) > 0) {
				$into[$counter++] = $e;
			}
		}
	}
	
	# Returns the number of pages after filtering with the passed in vars
	function request_max() {
		$variables = [
			"format" => $this->format,
			"isAdult" => $this->isAdult
		];
		self::append_if_existing($variables);

		$http = new GuzzleHttp\Client;
		
		echo "- Sending\n";
		$start = time();
		$response = $http->post('https://graphql.anilist.co', [
			'json' => [
				'query' => self::MAX_PAGES_QUERY,
				'variables' => $variables,
			]
		]);
		$sec = time() - $start;
		echo "- Recieved response (" . $sec . "s)\n";

		$this->max_page = ((json_decode($response->getBody(), true))["data"]["Page"]["pageInfo"]["total"]);
	}
	
	# Request a media format by using page
	function request_page() {

		$page = rand(1, $this->max_page);
		echo "Using page: " . $page . "\n";

		$variables = [
			"format" => $this->format,
			"page" => $page,
			"isAdult" => $this->isAdult
		];
		self::append_if_existing($variables);

		$http = new GuzzleHttp\Client;
		
		echo "- Sending\n";
		$start = time();
		$response = $http->post('https://graphql.anilist.co', [
			'json' => [
				'query' => self::QUERY,
				'variables' => $variables,
			]
		]);
		$sec = time() - $start;
		echo "- Recieved response (" . $sec . "s)\n";

		# Used for logging if the parent is not of the same format
		$original_id = (json_decode($response->getBody(), true))["data"]["Page"]["media"][0]["id"];

		$found_parent = self::find_parent($response->getBody());
		while (!is_null($found_parent)) {
			$response = self::request_id($found_parent);
			$found_parent = self::find_parent($response->getBody());
		}

		if ($response->getBody() == $this->empty_msg) {
			echo "WARNING empty on iteration " . $this->iteration . "\n";
			echo "- Requested Format: " . $this->format . "\n";
			echo "- Returned ID: " . $original_id . "\n";
			echo "- Parent ID: " . $found_parent . "\n";
		}

		return $response;
	}

	# Request a media format by using id
	function request_id($id) {
		$variables = [
			"format" => $this->format,
			"page" => 1,
			"id" => $id,
			"isAdult" => $this->isAdult
		];
		self::append_if_existing($variables);

		$http = new GuzzleHttp\Client;
		$response = $http->post('https://graphql.anilist.co', [
			'json' => [
				'query' => self::QUERY,
				'variables' => $variables,
			]
		]);

		return $response;
	}

	# If the current media has a parent or prequel, return its ID
	function find_parent($json) {
		$edges = (json_decode($json, true))["data"]["Page"]["media"][0]["relations"]["edges"];

		for ($i = 0; $i < count($edges); $i++) {
			if (($edges[$i]["relationType"] == "PARENT" || $edges[$i]["relationType"] == "PREQUEL")
				&& $edges[$i]["node"]["startDate"]["year"] <= self::get_year($json)) {
				return $edges[$i]["node"]["id"];
			}
		}
	}

	# Appends variables into the variable array for graphql communication
	function append_if_existing(&$array) {
		if (count($this->genres) > 0) {
			$array["genre_in"] = $this->genres;
		}
		if (count($this->not_genres) > 0) {
			$array["genre_not_in"] = $this->not_genres;
		}
		if (count($this->tags) > 0) {
			$array["tag_in"] = $this->tags;
		}
		if (count($this->not_tags) > 0) {
			$array["tag_not_in"] = $this->not_tags;
		}
		if ($this->startDate_greater > 0) {
			$array["startDate_greater"] = intval($this->startDate_greater . "1231");
		}
		if ($this->startDate_lesser > 0) {
			$array["startDate_lesser"] = intval($this->startDate_lesser . "0101");
		}
	}
}

$server = "irc.rizon.net";
$port = 6667;
$nickname = "medukatheguc";
$ident = "MeduBot";
$gecos = "12/F/Japan";
$channel = "#meguca";
$debug_user = "Medu"; # Change this to your IRC nick for testing in PMs

echo "> connecting to socket\n";
$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
$error = socket_connect($socket, $server, $port);

if ( $socket === false ) {
	$errCode = socket_last_error();
	$errStr = socket_strerror($errCode);
	die("ERROR, $errStr");
}

socket_write($socket, "NICK $nickname\r\n");
socket_write($socket, "USER $ident * 8 :$gecos\r\n");

$c = new comm;

$fish = [];
$fish.array_push($fish, new fish(["><>"], ["alive fish"], 15, true)); 
$fish.array_push($fish, new fish(["\x0311><>\x03"], ["cold fish"], 15, true));
$fish.array_push($fish, new fish(["\x0304><>\x03"], ["warm fish"], 15, true));
$fish.array_push($fish, new fish(["\x0303><>\x03"], ["dead fuck"], 15, true));
$fish.array_push($fish, new fish(["\x0304>\x03<\x0304>\x03"], ["clown fish"], 10, true));
$fish.array_push($fish, new fish(["\x0316,11><>\x03"], ["frozen fish"], 10, true));
$fish.array_push($fish, new fish(["\x0301,01>->\x03"], ["skellington fish"], 10, true));
$fish.array_push($fish, new fish(["><🐱"], ["cat fish"], 10, true));
$fish.array_push($fish, new fish(["🐟"], ["what the fuck"], 10, true));
$fish.array_push($fish, new fish(["\x0300,01>\x03\x0306,01<>\x03"], ["code fish"], 10, true));
$fish.array_push($fish, new fish([""], ["weird fish"], 10, true)); // Need to manually set the img each time this is called to allow for unique colours
$fish.array_push($fish, new fish(["\x0304,11><>\x03"], ["boiling fish"], 10, true));
$fish.array_push($fish, new fish(["\x0303>><>\x03"], ["\x0303implying fish\x03"], 10, true));
$fish.array_push($fish, new fish(["\x0303><>\x03\x0304~\x03"], ["snek fish"], 10, true));
$fish.array_push($fish, new fish(["ᛟ"], ["runic fish"], 10, true));
$fish.array_push($fish, new fish(["ᗙᗉᗇ"], ["canadian fish"], 10, true));
$fish.array_push($fish, new fish(["\x02\x0308><>\x03\x02"], ["gold fish"], 10, true));
$fish.array_push($fish, new fish(["\x0311>9>\x03"], ["dumb fish"], 9, true));
$fish.array_push($fish, new fish(["><\x0307|\x03        \x02PORTAL FISH\x02        \x0312|\x03>"], [""], 5, false));
$fish.array_push($fish, new fish([" \/\\", " /\/"], ["yo mama", "fish"], 5, false));
$fish.array_push($fish, new fish([" /\\", " \/", " /\\"], ["longfish", "is", "long"], 5, false));
$fish.array_push($fish, new fish(["(((><>)))"], ["who's behind this fish"], 5, true));
$fish.array_push($fish, new fish(["me with tags fish"], [""], 5, false));
$fish.array_push($fish, new fish([""], ["autistic fish"], 3, true)); // Need to manually set the img each time this is called to set name
$fish.array_push($fish, new fish(["\x0313/\x03>\x0313/\x03<\x0313/\x03>\x0313/\x03 < \x0313chuu~♥\x03"], ["super ultra hyper mirakuru romanfish"], 1, false));
$fish.array_push($fish, new fish(["\x0301,01><>\x03"], ["\x0301,01super ultra secret ninja fish\x03"], 1, true));
$fish.array_push($fish, new fish(["https://www.youtube.com/watch?v=3Vn2xrSG24w"], [""], 1, false));
$fish.array_push($fish, new fish(["|>"], ["shy fish"], 1, false));
$fish.array_push($fish, new fish(["\x0300,01\x02> ><> ><> ><> ><>\x02 AHN~\x03"], ["me and fish watching the stars"], 1, false));
$fish.array_push($fish, new fish(["><>"], ["depressed fish"], 1, true));

$fish_max = 0;
foreach ($fish as $f) {
	$fish_max += $f->val;
}

if (!file_exists(__DIR__ . "/../docs")) {
	mkdir(__DIR__ . "/../docs", 0755);
	echo "\n\n\n\nMADE\n\n\n\n";
}

while ( is_resource($socket)) {
	$data = trim(socket_read($socket, 1024, PHP_NORMAL_READ));
	echo $data . "\n";
	$d = explode(' ', $data);

	# Recipient of the message -- Send to user when debugging
	$sendto = DEBUG ? $debug_user : $d[2];

	if ($d[0] === 'PING') {
		echo "Sending PONG\n";
		socket_write($socket, 'PONG ' . $d[1] . "\r\n");
	}

	if ($d[1] === '376' || $d[1] === '422') {
		if (DEBUG === false) {
			echo "Connecting to channel\n";
			socket_write($socket, 'JOIN ' . $channel . "\r\n");
		}
	}
	else if ($data == ":NickServ!service@rizon.net NOTICE medukatheguca :please choose a different nick.") {
		$pw = file("pw")[0];
		socket_write($socket, "PRIVMSG NickServ :IDENTIFY $pw\n\n");
	} 
	else if ($d[3] == ':.ping') {
		socket_write($socket, 'PRIVMSG ' . $sendto . " :pong\r\n");
	}
	else if ($d[3] == ':.r' && (strtoupper($d[4]) == 'ANIME' || strtoupper($d[4]) == 'SHORT' || strtoupper($d[4]) == 'MOVIE' || strtoupper($d[4]) == 'SPECIAL' || strtoupper($d[4]) == 'OVA' || strtoupper($d[4]) == 'ONA' || strtoupper($d[4]) == 'MUSIC' || strtoupper($d[4]) == 'MANGA' || strtoupper($d[4]) == 'NOVEL' || strtoupper($d[4]) == 'ONE_SHOT')) {

		$adult = false;
		$genres = [];
		$not_genres = [];
		$tags = [];
		$not_tags = [];
		$invalid = [];
		$startDate_greater = 0;
		$startDate_lesser = 0;

		$type = "ANIME";
		if (strtoupper($d[4]) == "MANGA" || strtoupper($d[4]) == "NOVEL" || strtoupper($d[4]) == "ONE_SHOT") {
			$type = "MANGA";
		}

		$i = 5;
		while ($i < count($d)) {
			$word = ucwords($d[$i]);

			if ($word == "Adult") {
				$adult = true;
			}
			else if (substr($word, 0, 1) == ">" && strlen($word) == 5){
				$startDate_greater = substr($word, 1, 4);
			}
			else if (substr($word, 0, 1) == "<" && strlen($word) == 5){
				$startDate_lesser = substr($word, 1, 4);
			}
			else {
				# Loop through the genres and tags array to check if there are any matches
				$merged = array_merge($c->get_genres(), $c->get_tags());
				foreach ($merged as $ind => $arr) {

					$found_word = " ";
					$negate = false;

					for ($x = 0; $x < count($arr); $x++) {
						$word = strtolower($d[$i + $x]);

						# Check if the word is negating a tag/genre
						if ($x == 0 && substr($word, 0, 1) == "-") {
							$negate = true;
							$word = ltrim($word, "-");
						}

						$found_word = $found_word . " " . $arr[$x];
						if ($word != strtolower($arr[$x])) {
							if ($ind + 1 == count($merged) && array_search($word, $invalid) === false) {
								array_push($invalid, $word);
							}
							break;
						}
						else if ($x + 1 == count($arr)) {
							if ($negate) {
								$ind < count($c->get_genres()) ? array_push($not_genres, trim($found_word)) : array_push($not_tags, trim($found_word));
							}
							else {
								$ind < count($c->get_genres()) ? array_push($genres, trim($found_word)) : array_push($tags, trim($found_word));
							}

							$i = $i + $x;
							break 2;
						}
					}
				}
			}

			$i++;
		}

		echo "\nGenres:\n";
		var_dump($genres);
		echo "\nNot Genres:\n";
		var_dump($not_genres);
		echo "\nTags:\n";
		var_dump($tags);
		echo "\nNot Tags:\n";
		var_dump($not_tags);
		echo "\nStart Date Greater Than:\n";
		echo "\n" . $startDate_greater . "\n";
		echo "\nStart Date Lesser Than:\n";
		echo "\n" . $startDate_lesser . "\n";

		$body = $c->recommendation(strtoupper($d[4]), $adult, $genres, $not_genres, $tags, $not_tags, $startDate_greater, $startDate_lesser);

		echo "Laying out the string\n";
		$str = convert($body, $type);

		echo "[id]: " . $c->get_id($body) . "\n"; 

		if (count($invalid) > 0) {
			$msg = " :\x0307\x02<<Warning>>\x02 Invalid tags:\x03 ";
			foreach ($invalid as $i) {
				$msg = $msg . $i . " ";
			}
			$msg = $msg . "\r\n";
			echo "[msg]: " . $msg . "\n";
			socket_write($socket, 'PRIVMSG ' . $sendto . " :" . $msg . "\r\n");
		}
		echo "[str]: " . $str . "\n";
		socket_write($socket, 'PRIVMSG ' . $sendto . " :" . $str . "\r\n");
	}
	else if ($d[3] == ":.fish") {
		$r = rand(1, $fish_max);
		$curr_val = 0;

		echo "Max value: " . $fish_max . "\n";
		echo "Random number: " . $r . "\n";

		foreach ($fish as $f) {
			if (($curr_val += $f->val) >= $r) {

				for ($i = 0; $i < count($f->img); $i++) {
					$img;
					switch ($f->name[0]) {
						case "weird fish":
							$img = weird_fish();
							break;
						case "autistic fish":
							$img = autistic_fish(get_name($d[0]));
							break;
						default:
							$img = $f->img[$i];
							break;
					}
					$name = $f->name[$i];

					if ($f->cliqueable && rand(0, 50) == 25) {
						for ($j = 0; $j < 4; $j++) {
							switch ($f->name[0]) {
								case "weird fish":
									$img = $img . " " . weird_fish();
									break;
								case "autistic fish":
									$img = $img . " " . autistic_fish(get_name($d[0]));
									break;
								default:
									$img = $img . " " . $f->img[$i];
									break;
							}

						}
						switch ($name) {
							case "alive fish":
								$name = "fish clique";
								break;
							case "\x0303implying fish\x03":
								$name = "\x0303implying fish clique\x03";
								break;
							case "\x0301,01super ultra secret ninja fish\x03":
								$name = "\x0301,01super ultra secret ninja fish clique\x03";
								break;
							default:
								$name = $name . " clique";
								break;
						}
					}

					socket_write($socket, 'PRIVMSG ' . $sendto . " :" . $img . " " . $name . "\r\n");
				}
				break;
			}
		}
	}
	else if ($d[3] == ":hsif.") {
		socket_write($socket, "PRIVMSG " . $sendto . " :hsif sdrawkcab <><\r\n");
	}
	else if ($d[3] == ":.suggestfish" && !is_null($d[4])) {
		$line = $d[4];
		$i = 5;
		while (!is_null($d[$i])) {
			$line = $line . " " . $d[$i++];
		}
		socket_write($socket, "PRIVMSG " . $sendto . " :Got it! $line added to the suggestions file\r\n");
		$line = $line . "\n";
		echo $line;

		$fp = fopen("../docs/suggestfish", "a+");
		fwrite($fp, $line);
		fclose($fp);
	}
	else if ($d[3] == ":.test") {
		socket_write($socket, "PRIVMSG " . $sendto . " :There's nothing to test, dummy\r\n");
	}
	else if ($d[3] == ":.joke") {
		socket_write($socket, "PRIVMSG " . $sendto . " :ba dum tss\r\n");
	}
	else if (strtolower($d[3]) == ":ohayou" && strtolower($d[4]) == "medukatheguca") {
		socket_write($socket, "PRIVMSG " . $sendto . " :morning fish ><>☕\r\n");
	}
	else if ((strtolower($d[3]) == ":good" || strtolower($d[3]) == ":cute") && strtolower($d[4]) == "bot") {
		socket_write($socket, "PRIVMSG " . $sendto . " :ababababababababababababba\r\n");
	}
	else if ((strtolower($d[3]) == ":slutty") && strtolower($d[4]) == "bot") {
		socket_write($socket, "PRIVMSG " . $sendto . " :Ahn~\r\n");
	}
	else if (strtolower($d[3]) == ":dumb") {
		socket_write($socket, "PRIVMSG " . $sendto . " :nou\r\n");
	}
	else if ($d[3] == ":.shower") {

		$lines = file(__DIR__ . "/../docs/shower", FILE_IGNORE_NEW_LINES);

		$fp = fopen(__DIR__ . "/../docs/shower", "w+");

		$name = get_name($d[0]);
		$total = 1;
		$usr_total = 1;

		if (!$lines) {
			fwrite($fp, "total 1\n");
			fwrite($fp, "$name 1\n");
		} else {
			$found = false;
			
			foreach ($lines as &$line) {
				echo "\n\n\nLINE BEFORE: \"" . $line . "\"\n";
				$words = explode(' ', $line);
				if ($words[0] == "total") {
					$words[1] = intval($words[1]) + 1;
					$total = $words[1];
				}
				else if ($words[0] == $name) {
					$words[1] = intval($words[1]) + 1;
					$usr_total = $words[1];
					$found = true;
					$line = implode(" ", $words);
					break;
				}
				$line = implode(" ", $words);
			}
		
			if (!$found) {
				array_push($lines, $name . " 1");
			}

			var_dump($lines);

			fwrite($fp, implode("\n", $lines));
		}

		fclose($fp);

		var_dump($lines);

		socket_write($socket, "PRIVMSG " . $sendto . " :" . $name . " took a shower and is no longer stinky!\r\n");
		socket_write($socket, "PRIVMSG " . $sendto . " :" . $name  . " has taken " . $usr_total . " " . ($usr_total == 1 ? "shower" : "showers") . ". Total number of showers taken: ". $total . "\r\n");
	}
}

function get_name($str) {
	$s = ltrim($str, ':');
	return substr($s, 0, strpos($s, '!'));
}

function weird_fish() {
	return "\x03" . rand(0, 15) . ">\x03\x03" . rand(0, 15) . "<\x03\x03" . rand(0, 15) . ">\x03";
}

function autistic_fish($name) {
	return "><" . $name . ">";
}

function convert($str, $type) {

	$arr = (json_decode($str, true))["data"]["Page"]["media"][0];
	$id = $arr["id"];

	if (is_null($id)) {
		echo "[ERROR]: " . $str . "\n";
		return "\x0304\x02!!ERROR!!\x02 Couldn't find any entries. Did you fuck something up?\x03\r\n";
	}

	$edges = $arr["relations"]["edges"];

	for ($i = 0; $i < count($edges); $i++) {
		if ($edges[$i]["relationType"] == "PARENT") {
			$id = $edges[$i]["node"]["id"];
		}
	}

	$title = $arr["title"]["romaji"];
	$year = $arr["startDate"]["year"];
	$episodes = $arr["episodes"];
	$volumes = $arr["volumes"];
	$genres = $arr["genres"];
	$not_genres = $arr["not_genres"];
	$tags = $arr["tags"];
	$not_tags = $arr["not_tags"];
	$mediaFormat = $arr["format"];

	$rs = "\x0309\x02" . $title . "\x03\x02\x0303";

	if (!is_null($year)) {
		$rs = $rs . " [" . $year . "]";
	}

	$total = count($genres) + count($tags);
	for ($i = 0; $i < $total; $i++) {
		if ($i == 0) {
			$rs = $rs . " (";
		}
		if ($i < count($genres)) {
			$rs = $rs . $genres[$i];
		}
		else {
			$rs = $rs . $tags[$i - count($genres)]["name"];
		}
		if ($i + 1 < $total) {
			$rs = $rs . ", ";
		}
		else {
			$rs = $rs . ")";
		}
	}

	if ($type == "ANIME" && !is_null($episodes)) {
		$rs = $rs . " -- " . $episodes . "ep ";
	}
	else if ($type == "MANGA" && !is_null($volumes)) {
		$rs = $rs . " -- " . $volumes . "vol ";
	}

	$rs = $rs . "<https://anilist.co/" . strtolower($type) . "/" . $id . "/>";

	if (!is_null($arr["idMal"])) {
		$rs = $rs . " or <https://myanimelist.net/" . strtolower($type) . "/" . $arr["idMal"] . "/>";
	}

	$rs = $rs . "\x03\n";

	return $rs;
}

?>
