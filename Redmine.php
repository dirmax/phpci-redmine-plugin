<?php
namespace Intaro\PHPCI\Plugin;

use PHPCensor\Builder;
use PHPCensor\Plugin;
use PHPCensor\Model\Build;

/**
 * Update related Redmine issue with build status
 */
class Redmine extends Plugin
{
    const STATUS_PASSED = 2;
    const STATUS_FAILED = 3;

    protected $phpci;
    protected $build;

    protected $server;
    protected $apiKey;
    protected $issueRegexp = '/#(\d+)/';

    protected $enabled = true;

    protected $status;
    protected $prevStatus;
    protected $percent;
    protected $urlBuild;

    protected $lang = 'en';
    protected $messages = [
        'passed'    => 'Passed %s',
        'failed'    => 'Failed %s',
    ];

    public function __construct(Builder $phpci, Build $build, array $options = array())
    {
        $this->phpci = $phpci;
        $this->build = $build;

        $buildSettings = $phpci->getConfig('build_settings');

        if (isset($buildSettings['redmine'])) {
            $redmine = $buildSettings['redmine'];

            $this->server = $redmine['server'];
            $this->apiKey = $redmine['api_key'];
        }

        if (isset($options['enabled'])) {
            $this->enabled = $options['enabled'];
        }
        if (isset($options['status'])) {
            $this->status = $options['status'];
        }
        if (isset($options['prev_status'])) {
            $this->prevStatus = $options['prev_status'];
        }
        if (isset($options['percent'])) {
            $this->percent = $options['percent'];
        }
        if (isset($options['lang'])) {
            $this->lang = $options['lang'];
        }
        if (isset($options['issue_regexp'])) {
            $this->issueRegexp = $options['issue_regexp'];
        }
        if (isset($options['url_build'])) {
            $this->urlBuild = $options['url_build'];
        }
    }

    public function execute()
    {
        if (!$this->enabled) {
            return true;
        }

        $matches = array();
        if (!preg_match($this->issueRegexp, $this->build->getCommitMessage(), $matches)) {
            return true;
        }

        $url = $this->server . '/issues/' . $matches[1] . '.json';
        $issue = array();

        $issue['notes'] =
            'Commit: *' . $this->build->getCommitMessage() . "*\n"
            . ($this->urlBuild
                ? 'Build URL: ' . $this->phpci->interpolate($this->urlBuild)  . "\n"
                : ''
            )

            . '!' . $this->phpci->getSystemConfig('php-censor.url') . '/build-status/image/' .
            $this->build->getProjectId() . '?branch=' . $this->build->getBranch() . '!' .
            "\n\n"
        ;

        $buildLink = $this->phpci->getSystemConfig('php-censor.url') . '/build/view/' . $this->build->getId();

        if (self::STATUS_PASSED == $this->build->getStatus()) {
            $issue['notes'] .= sprintf($this->messages['passed'], $buildLink) . "\n";

            if ($this->status) {
                if ($this->prevStatus) {
                    $headers = array(
                        'Content-Type: application/json',
                        'X-Redmine-API-Key: ' . $this->apiKey,
                    );

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_FAILONERROR, false);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // return into a variable
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // times out after 30s

                    $response = curl_exec($ch);
                    $response = json_decode($response, true);

                    if ($response) {
                        if (isset($response['issue']['status']['id'])) {
                            if ($this->prevStatus == $response['issue']['status']['id']) {
                                $issue['status_id'] = $this->status;
                                if ($this->percent) {
                                    $issue['done_ratio'] = $this->percent;
                                }
                            }
                        }
                    }
                } else {
                    $issue['status_id'] = $this->status;
                    if ($this->percent) {
                        $issue['done_ratio'] = $this->percent;
                    }
                }
            }

        } elseif (self::STATUS_FAILED == $this->build->getStatus()) {
            $issue['notes'] .= sprintf($this->messages['failed'], $buildLink) . "\n";
            $issue['notes'] .= '{{collapse(View details...)' . "\n"
                . '<pre>' . $this->build->getLog() . "</pre>\n"
            . '}}' . "\n";
        }

        $headers = array(
            'Content-Type: application/json',
            'X-Redmine-API-Key: ' . $this->apiKey,
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // return into a variable
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // times out after 30s
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array( 'issue' => $issue )));

        $responseBody = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if (200 !== $statusCode) {
            $this->phpci->logFailure(sprintf(
                'Failed to update Redmine issue. Details: status code = %s, response = %s, errno = %s, error = %s.',
                $statusCode,
                $responseBody,
                $errno,
                $error
            ));

            return false;
        }

        return true;
    }
}
