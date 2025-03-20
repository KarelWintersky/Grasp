<?php

namespace App\Units;

use Arris\Entity\Result;

class GitHelper
{
    public const SUPPORTED = [
        'GitHub',
        'GitFlic'
    ];


    /**
     * @param $url
     * @return bool
     */
    public static function checkRemoteRepoExists(string $url):bool
    {
        // $process = new Process(['ls', '-lsa']);
        // $process->run();

        exec("git ls-remote $url", $output, $returnVar);
        return $returnVar === 0;
    }

    /**
     * Клонирует репозиторий
     * @param string $url
     * @param string $target
     * @return bool
     */
    public static function cloneBareRepository(string $url, string $target): bool
    {
        $command = "git clone --bare $url $target";
        exec($command, $output, $returnVar);

        return $returnVar === 0;
    }

    /**
     * Вычисляет размер скачанного репозитория
     *
     * @param string $target
     * @return int
     */
    public static function getLocalSize(string $target):int
    {
        $size = 0;
        if (is_dir($target)) {
            $size = shell_exec("du -sb $target | cut -f1");
        }
        return (int)$size;
    }

    public static function getLocalDescription(string $target):string
    {
        return shell_exec("git --git-dir=$target config --get remote.origin.url");
    }

    /**
     * Парсит URL репозитория и разбирает его на составные части
     *
     * @param string $url
     * @return Result
     */
    public static function parseGitUrl(string $url):Result
    {
        $r = new Result();

        if (preg_match('/https:\/\/github\.com\/([^\/]+)\/([^\/]+)/', $url, $matches))
        {
            // GitHub URL
            $r->type = 'Github';
            $r->username = $matches[1];
            $r->reponame = $matches[2];

        }
        elseif (preg_match('/https:\/\/gitflic\.ru\/project\/([^\/]+)\/([^\/]+)/', $url, $matches))
        {
            $r->type = 'GitFlic';
            $r->username = $matches[1];
            $r->reponame = $matches[2];
        }
        else
        {
            $r->error("Неверный формат URL. Поддерживаются: " . implode(', ', self::SUPPORTED ));
        }

        return $r;
    }




}