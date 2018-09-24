<?php
/**
 * @file
 * Support for pulling information from a remote GIT repository.
 */

namespace CL\Git;

use CL\Site\Site;
use CL\Course\Section;
use CL\Users\User;
use CL\Team\Teams;
use CL\Team\Teamings;
use CL\Team\Submission\TeamSubmissions;

/**
 * Exception class for Git errors
 */
class GitException extends \Exception {
	public function __construct($msg) {
		parent::__construct($msg);
	}
}

/**
 * Support for pulling information from a remote GIT repository.
 *
 * Create a team submission of type text for the team to enter their
 * GIT url into the system.
 *
 * Example usage:
 * @code
 * $git = new \CL\Git\Git($site, $teamId);
 * $git->set_account("https://git.cse.msu.edu", "cse335", "accesstoken");
 * $git->set_url_submission("project1", "git");
 * $ret = $git->connect();
 * if($ret !== null) {
 *    // Display error message
 *   echo $ret;
 * } else {
 *   // Display results
 *   echo $git->present_team();
 *   echo $git->present_commits();
 * }
 * @endcode
 */
class Git {
	/**
	 * Git constructor.
	 * @param Site $site The CourseLib Site object
	 * @param int $teamId The team internal ID
	 */
	public function __construct(Site $site, $teamId) {
		$this->site = $site;

		// Get the team
		$teams = new Teams($site->db);
		$this->team = $teams->get($teamId);

		// Get the teaming
		if($this->team !== null) {
			$teamings = new Teamings($site->db);
			$this->teaming = $teamings->getById($this->team->teamingId);
		}
	}

	/**
	 * Set the GIT account to access
	 * @param string $server Server to access, like 'https://git.cse.msu.edu'
	 * @param string $user User ID for account on the server
	 * @param string $token Token that allows for access to that account
	 */
	public function set_account($server, $user, $token) {
		$this->gitServer = $server;
		$this->gitUser = $user;
		$this->gitToken = $token;
	}

	/**
	 * Set the submission information so the system knows where to
	 * find the GIT URL.
	 * @param string $assignTag Assignment tag, identifies the assignment
	 * @param string $submissionTag Submission tag, identifies the submission
	 */
	public function set_url_submission($assignTag, $submissionTag) {
		$this->assignTag = $assignTag;
		$this->submissionTag = $submissionTag;
	}

	/**
	 * Limit results to only activity after a given time
	 * @param int $since Time we only are interested in activity since (Unix time)
	 */
	public function set_since($since) {
	    $this->since = strtotime($since);
    }

	/**
	 * Connect to GIT
	 * @return string HTML error if failure or null if success
	 */
	public function connect() {
		try {
			// Get the GIT URL
			$this->get_url();

			// Get project information
			$this->get_project();

		} catch(GitException $exception) {
			return $exception->getMessage();
		}

		return null;
	}


	/**
	 * Get the team-provide URL
	 * @throws GitException
	 */
	private function get_url() {
		$teamSubmissions = new TeamSubmissions($this->site->db);
		$submissions = $teamSubmissions->get_submissions($this->team->id, $this->assignTag, $this->submissionTag, true);
		if(count($submissions) === 0) {
			throw new GitException("<p>Team has not set GIT URL.</p>");
		}

		$submission = $teamSubmissions->get_text($submissions[0]['id']);
		$this->gitURL = trim($submission['text']);

		// Parse it
		$regex = "#" . $this->gitServer . "/(.*/.*)\.git#";
		$matches = array();
		if(preg_match($regex, $this->gitURL, $matches) !== 1 || count($matches) < 2) {
			throw new GitException("<p>Team provided GIT URL is invalid.</p>" .
			    "<p>$this->gitURL</p>");
		}

		$this->projectName = $matches[1];

	}

	/**
	 * Get the project information.
	 *
	 * Sets:
	 * $this->projectInfo
	 * $this->projectId
	 *
	 * @throws GitException
	 */
	private function get_project() {
		$url = $this->gitServer . "/api/v4/projects/" .
			 rawurlencode($this->projectName) . "?private_token=" . $this->gitToken;

		$json = $this->gitlab($url);
		if($json === false) {
			throw new GitException("<p>Unable to retrieve project information for ' . 
				$this->gitURL . '</p>");
		}

		$this->projectInfo = json_decode($json, true);

		if(isset($this->projectInfo['message'])) {
			throw new GitException('<p class="center">Unable to access GIT: ' .
					$this->projectInfo['message'] . " " . $this->gitURL . "</p>");
		}

		$this->projectId = $this->projectInfo['id'];
	}

	/**
	 * Present the team results
	 * @return string HTML
	 */
	public function present_team() {
		$teamName = $this->team->name;
		$teamingName = $this->teaming->name;
		$html = <<<HTML
<p class="full center">Teaming: $teamingName Team: $teamName &nbsp;&nbsp;<a class="small" href="$this->gitURL">$this->gitURL</a></p>
HTML;
		return $html;
	}

	/**
	 * Present the team commits.
	 * @return string HTML
	 */
	public function present_commits() {
	    $commits = [];

	    for($page=1; $page <= 10; $page++) {
            $url = $this->gitServer . "/api/v4/projects/" . $this->projectId .
                "/repository/commits?page=" . $page . "&per_page=50&private_token=" . $this->gitToken;

            $json = $this->gitlab($url);
            if($json === false) {
                return "<p>Unable to retrieve project commits</p>";
            }

            $newCommits = json_decode($json, true);

            if(isset($newCommits['message'])) {
                return "<p>Unable to access: " . $newCommits['message'] . "</p>";
            }

            foreach($newCommits as $commit) {
                if($this->since !== null) {
                    if(strtotime($commit['created_at']) > $this->since) {
                        $commits[] = $commit;
                    }
                } else {
                    $commits[] = $commit;
                }
            }

            if(count($newCommits) < 40) {
                break;
            }
        }

		/*
		 * Each commit:
		 *
		 * {"id":"8177882d6197917c7c17fe6ba19de07740261c8c",
		 *  "short_id":"8177882d619",
		 *  "title":"Changes preparing for Edinburgh",
		 *  "author_name":"Charles Owen",
		 *  "author_email":"cbowen@cse.msu.edu",
		 *  "created_at":"2015-08-21T10:16:59-04:00"},
		 */
		$commits2 = [];

        foreach($commits as $commit) {
            $commitId = $commit['id'];

            $url = $this->gitServer . "/api/v4/projects/" . $this->projectId .
                "/repository/commits/$commitId?page=" . $page . "&per_page=50&private_token=" . $this->gitToken;
            $json = $this->gitlab($url);
            if($json === false) {
                return "<p>Unable to retrieve project commit</p>";
            }

            $commitInfo = json_decode($json, true);
            $stats = $commitInfo['stats'];
            $additions = $stats['additions'];
            $deletions = $stats['deletions'];

            $commit['additions'] = $additions;
            $commit['deletions'] = $deletions;
            $commits2[] = $commit;
        }

        $commits = $commits2;

		/*
		 * Compute author statistics
		 */
		$authors = array();
        $totalCommits = count($commits);
        $totalAdditions = 0;
        $totalDeletions = 0;

        foreach($commits as $commit) {
			$author = $commit['author_name'];
			$email = $commit['author_email'];
			$id = $email;
			if(preg_match("/^(.*)@/", $email, $matches) && count($matches) > 1) {
				$id = $matches[1];
			}

			if(!isset($authors[$id])) {
                $authors[$id] = array("name" => $author,
                    "email" => $email,
                    "count" => 0,
                    "additions" => 0,
                    "deletions" => 0,
                    'id' => $id
                );
            }

            $authors[$id]["count"] += 1;

            $additions = $commit['additions'];
            $deletions = $commit['deletions'];
			$authors[$id]['additions'] += $additions;
            $authors[$id]['deletions'] += $deletions;

            $totalAdditions += $additions;
            $totalDeletions += $deletions;
		}

		if($totalCommits == 0) {
			$totalCommits = 1;
		}

		$afterNotice = '';
        if($this->since !== null) {
            $date = date("m-d-Y", $this->since);
            $afterNotice = <<<HTML
<p class="small center"><em>Only commits after $date are considered.</em></p>
HTML;

        }

		$html = <<<HTML
<div class="full">$afterNotice
<p class="small center"><em>$totalCommits GIT commits! $totalAdditions Additions/$totalDeletions Deletions</em></p>
<table class="small">
<tr><th>Name</th><th>Id</th><th>Email</th><th>Commits</th><th>%</th><th>Additions</th><th>Deletions</th></tr>
HTML;

		foreach($authors as $id => $info) {
			$name = $info['name'];
			$count = $info['count'];
			$email = $info['email'];
            $additions = $info['additions'];
            $deletions = $info['deletions'];

            $addper = $totalAdditions > 0 ? round($additions / $totalAdditions * 100, 1) : 0;
            $delper = $totalDeletions > 0 ? round($deletions / $totalDeletions * 100, 1) : 0;

			$per = round($info['count'] / $totalCommits * 100, 1);
			$html .= <<<HTML
<tr><td>$name</td><td>$id</td><td>$email</td><td>$count</td><td>$per</td><td>$additions/$addper%</td><td>$deletions/$delper%</td></tr>
HTML;
		}

		$html .= "</table></div>";

		$html .= <<<HTML
<p></p>
<div class="full">
<table class="small">
<tr><th>Name</th><th>Email</th><th>Time</th><th>Message</th></tr>
HTML;

		foreach($commits as $commit) {
			$author = $commit['author_name'];
			$email = $commit['author_email'];
			$time = strtotime($commit['created_at']);
			$title = $commit['title'];

			$tm = date("h:i:sa", $time);
			$dt = date("m-d-Y", $time);

			$html .= <<<HTML
<tr><td>$author</td><td>$email</td><td>$dt $tm</td><td>$title</td></tr>
HTML;


		}

		$html .= "</table></div>";
		return $html;
	}


	private function gitlab($url) {
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: text/html; charset=utf-8'));
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

		$json = curl_exec($curl);

		curl_close($curl);
		return $json;
	}

    private function gitlab_with_header($url) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: text/html; charset=utf-8'));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_HEADER, true);

        //$json = curl_exec($curl);
        $response = curl_exec($curl);
        $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);
        $json = substr($response, $header_size);

        curl_close($curl);
        return ['json'=>$json, 'header'=>$header];
    }


	private $site;

	private $gitServer = null;
    private $gitUser = null;
	private $gitToken = null;

	private $assignTag = null;
	private $submissionTag = null;

	private $projectName = null;
	private $projectInfo = null;
	private $projectId = null;

	private $team = null;
	private $teaming = null;

	private $gitURL = null;

	private $since = null;
}