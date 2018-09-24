# CourseLib Git component

This is a component for the CourseLib library that makes it possible to
monitor team activity in a GitLab repository. It pulls statistics on team
members and all commit activity, providing a way to objectively measure
the contributions of individual team members.

***This is a work in progress***

##Installation

```
composer require cl/git
```


##Usage

```php
<?php
$git = new \CL\Git\Git($site, $teamId);
$git->set_account("https://git.cse.msu.edu", "cse335", "accesstoken");
$git->set_url_submission("project1", "git");
$ret = $git->connect();
if($ret !== null) {
  // Display error message
  echo $ret;
} else {
  // Display results
  echo $git->present_team();
  echo $git->present_commits();
}
?>
```

## License

Copyright 2016-2018 Michigan State University

CourseLib is released under the MIT license.

* * *

Written and maintained by Charles B. Owen

