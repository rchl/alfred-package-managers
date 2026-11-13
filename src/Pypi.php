<?php

namespace WillFarrell\AlfredPkgMan;


class Pypi extends Repo
{
    protected $id               = 'pypi';
    protected $url              = 'https://pypi.org';
    protected $search_url       = 'https://pypi.org/search/?q=';
    protected $min_query_length = 3;

    // README: pypi.org/search is behind a JavaScript challenge and there is no search API,
    //          so we look up the exact name via the JSON API and substring-match the rest
    //          against the full list of project names from the simple index
    protected $json_url   = 'https://pypi.org/pypi/';
    protected $index_url  = 'https://pypi.org/simple/';
    protected $index_file = 'pypi-index.txt';
    protected $index_age  = 7;

    public function search($query)
    {
        if (!$this->hasMinQueryLength($query)) {
            return $this->asJson();
        }

        $parts = preg_split('/[-_.\s]+/', strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY);
        $normalized = implode('-', $parts);

        $info = $this->cache->get_query_json(
            "{$this->id}-info",
            $normalized,
            "{$this->json_url}{$normalized}/json"
        );

        if (isset($info->info)) {
            $p = $info->info;
            $summary = $p->summary ?? '';

            $this->cache->w->result(
                "{$this->id}-{$p->name}",
                $this->makeArg($p->name, "{$this->url}/project/{$p->name}/", "{$p->name}=={$p->version}"),
                $p->name,
                $summary === '' ? $p->version : "{$p->version} · {$summary}",
                "icon-cache/{$this->id}.png"
            );
        }

        $pattern = implode('[-_.]+', array_map(function ($part) {
            return preg_quote($part, '/');
        }, $parts));
        preg_match_all("/^.*{$pattern}.*$/mi", $this->getIndex(), $matches);

        $names = [];
        foreach ($matches[0] as $name) {
            $key = strtolower(preg_replace('/[-_.]+/', '-', $name));
            if ($key === $normalized) {
                continue;
            }
            // exact prefix matches first, then shorter names
            $names[$name] = [strpos($key, $normalized) === 0 ? 0 : 1, strlen($name), $key];
        }
        asort($names);

        foreach (array_keys($names) as $name) {
            if (count($this->cache->w->results()) >= $this->max_return) {
                break;
            }

            $url = "{$this->url}/project/{$name}/";
            $this->cache->w->result(
                "{$this->id}-{$name}",
                $this->makeArg($name, $url),
                $name,
                $url,
                "icon-cache/{$this->id}.png"
            );
        }

        $this->noResults($query, "{$this->search_url}{$query}");

        return $this->asJson();
    }

    /**
     * Newline-separated list of all project names on PyPI, refreshed every few days
     *
     * @return string
     */
    protected function getIndex()
    {
        $index = $this->cache->w->read($this->index_file);
        $timestamp = $this->cache->w->filetime($this->index_file);

        if (!is_string($index) || $timestamp < (time() - $this->index_age * 86400)) {
            $data = json_decode($this->cache->w->request($this->index_url, [
                CURLOPT_HTTPHEADER => ['Accept: application/vnd.pypi.simple.v1+json'],
            ]), true);

            // keep the stale index if the download failed
            if (isset($data['projects'])) {
                $index = implode("\n", array_column($data['projects'], 'name'));
                $this->cache->w->write($index, $this->index_file);
            }
        }

        return is_string($index) ? $index : '';
    }
}

// $repo = new Pypi();
// echo $repo->search('lib');
