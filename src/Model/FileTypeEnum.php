<?php

namespace App\Model;

enum FileTypeEnum: string
{
    case UNKNOWN = 'unknown';
    case DIRECTORY = 'Directory';
    case IMAGE = 'Image';
    case MARKDOWN = 'Markdown';
    case VIDEO = 'Video';
    case DOCUMENT = 'Document';
    case TEXT = 'Text';
    case YAML = 'YAML';
    case JSON = 'JSON';
    case PHP = 'PHP';
    case HTML = 'HTML';
    case XML = 'XML';
    case JAVASCRIPT = 'JS';
    case PACKAGE_MANAGER = 'Package-Manager';
    case DOCKER = 'Docker';
    case GIT = 'git';
    case BINARY = 'binary';
    case CONFIG = 'Config';
    case TESTS = 'Tests';
    case LICENSE = 'License';
    case SQL = 'SQL';
    case SHELLSCRIPT = 'ShellScript';
    case WEBSERVER = 'Webserver Config';
}
