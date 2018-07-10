<?php

namespace TheAentMachine\AentPhp\Command;

use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use TheAentMachine\CommonEvents;
use TheAentMachine\EventCommand;
use TheAentMachine\Pheromone;
use TheAentMachine\Registry\RegistryClient;
use TheAentMachine\Service\Service;

class AddEventCommand extends EventCommand
{
    protected function getEventName(): string
    {
        return 'ADD';
    }

    /**
     * @throws \TheAentMachine\Exception\CannotHandleEventException
     */
    protected function executeEvent(?string $payload): ?string
    {
        $helper = $this->getHelper('question');

        $aentHelper = $this->getAentHelper();
        $commentEvents = new CommonEvents($aentHelper, $this->output);

        $commentEvents->canDispatchServiceOrFail();

        $service = new Service();

        /************************ Service name **********************/
        $serviceName = $aentHelper->askForServiceName('app', 'Your PHP application');
        $this->output->writeln("<info>You are about to create a '$serviceName' PHP container</info>");
        $service->setServiceName($serviceName);


        /************************ PHP Version **********************/
        [
            'phpVersions' => $phpVersions,
            'variants' => $variants,
            'nodeVersions' => $nodeVersions,
        ] = $this->getAvailableVersionParts();

        $question = new ChoiceQuestion(
            'Select your PHP version :',
            $phpVersions,
            0
        );
        $phpVersion = $helper->ask($this->input, $this->output, $question);
        $this->output->writeln("<info>You are about to install PHP $phpVersion</info>");
        $aentHelper->spacer();
        $aentHelper->spacer();


        $question = new ChoiceQuestion(
            'Select your variant :',
            $variants,
            0
        );
        $variant = $helper->ask($this->input, $this->output, $question);
        $this->output->writeln("<info>You selected the $variant variant</info>");
        $aentHelper->spacer();
        $aentHelper->spacer();

        $question = new ChoiceQuestion(
            'Do you want to install NodeJS :',
            array_merge(['No'], $nodeVersions),
            0
        );
        $node = $helper->ask($this->input, $this->output, $question);
        if ($node !== 'No') {
            $this->output->writeln("<info>The image will also contain $node</info>");
        } else {
            $this->output->writeln('<info>The image will not contain NodeJS</info>');
        }
        $aentHelper->spacer();
        $aentHelper->spacer();

        if ($node === 'No') {
            $node = '';
        } else {
            $node = '-'.$node;
        }

        $service->setImage("thecodingmachine/php:$phpVersion-v1-$variant$node");



        /************************ Root application path **********************/
        $this->output->writeln('Now, we need to find the root of your web application.');
        $appDirectory = $aentHelper->question('PHP application root directory (relative to the project root directory)')
            ->setHelpText('Your PHP application root directory is typically the directory that contains your composer.json file. It must be relative to the project root directory.')
            ->setValidator(function (string $appDirectory) {
                $appDirectory = trim($appDirectory, '/') ?: '.';
                $rootDir = Pheromone::getContainerProjectDirectory();

                $fullDir = $rootDir.'/'.$appDirectory;
                if (!is_dir($fullDir)) {
                    throw new \InvalidArgumentException('Could not find directory '.Pheromone::getHostProjectDirectory().'/'.$appDirectory);
                }
                return $appDirectory;
            })->ask();

        $this->output->writeln('<info>Your root PHP application directory is '.Pheromone::getHostProjectDirectory().'/'.$appDirectory.'</info>');
        $aentHelper->spacer();
        $aentHelper->spacer();

        $service->addBindVolume('./'.$appDirectory, '/var/www/html');

        /************************ Web application path **********************/
        if ($variant === 'apache') {
            $answer = $aentHelper->question('Do you have a public web folder that is not the root of your application?')
                ->yesNoQuestion()->setDefault('y')->ask();
            if ($answer) {
                $webDirectory = $aentHelper->question('Web directory (relative to the PHP application directory)')
                    ->setHelpText('Your PHP application web directory is typically the directory that contains your index.php file. It must be relative to the PHP application directory ('.Pheromone::getHostProjectDirectory().'/'.$appDirectory.')')
                    ->setValidator(function (string $webDirectory) use ($appDirectory) {
                        $webDirectory = trim($webDirectory, '/') ?: '.';
                        $rootDir = Pheromone::getContainerProjectDirectory();

                        $fullDir = $rootDir.'/'.$appDirectory.'/'.$webDirectory;
                        if (!is_dir($fullDir)) {
                            throw new \InvalidArgumentException('Could not find directory '.Pheromone::getHostProjectDirectory().'/'.$appDirectory.'/'.$webDirectory);
                        }
                        return $webDirectory;
                    })->ask();

                $service->addImageEnvVariable('APACHE_DOCUMENT_ROOT', $webDirectory);
                $this->output->writeln('<info>Your web directory is '.Pheromone::getHostProjectDirectory().'/'.$appDirectory.'/'.$webDirectory.'</info>');
                $aentHelper->spacer();
                $aentHelper->spacer();
            }
        }

        /************************ Upload path **********************/
        $this->output->writeln('Now, we need to know if there are directories you want to store <info>out of the container</info>.');
        $this->output->writeln('When a container is removed, anything in it is lost. If your application is letting users upload files, or if it generates files, it might be important to <comment>store those files out of the container</comment>.');
        $this->output->writeln('If you want to mount such a directory out of the container, please specify the directory path below. Path must be relative to the PHP application root directory.');
        $aentHelper->spacer();

        $uploadDirs = [];
        do {
            $question = new Question('Please input directory (for instance for file uploads) that you want to mount out of the container? (keep empty to ignore) ', '');
            $uploadDirectory = $helper->ask($this->input, $this->output, $question);

            $uploadDirectory = trim($uploadDirectory, '/');
            $rootDir = Pheromone::getContainerProjectDirectory();

            if ($uploadDirectory !== '') {
                $fullDir = $rootDir.'/'.$appDirectory.'/'.$uploadDirectory;
                if (!is_dir($fullDir)) {
                    $this->output->writeln('<error>Could not find directory '.Pheromone::getHostProjectDirectory().'/'.$appDirectory.'/'.$uploadDirectory.'</error>');
                    $uploadDirectory = null;
                } else {
                    $uploadDirs[] = $uploadDirectory;
                    $this->output->writeln('<info>Directory '.Pheromone::getHostProjectDirectory().'/'.$appDirectory.'/'.$uploadDirectory.' will be stored out of the container</info>');

                    $question = new Question('What name should we use for this volume? ', '');
                    $question->setValidator(function (string $value) {
                        $value = trim($value);
                        if (!\preg_match('/^[a-zA-Z0-9_.-]+$/', $value)) {
                            throw new \InvalidArgumentException('Invalid volume name "'.$value.'". Volume names can contain alphanumeric characters, and "_", ".", "-".');
                        }

                        return $value;
                    });
                    $volumeName = $helper->ask($this->input, $this->output, $question);

                    $service->addNamedVolume($volumeName, $appDirectory.'/'.$uploadDirectory);
                }
            }
        } while ($uploadDirectory !== '');
        $aentHelper->spacer();
        $aentHelper->spacer();

        $availableExtensions = ['amqp', 'ast', 'bcmath', 'bz2', 'calendar', 'dba', 'enchant', 'ev', 'event', 'exif',
            'gd', 'gettext', 'gmp', 'igbinary', 'imap', 'intl', 'ldap', 'mcrypt', 'memcached', 'mongodb', 'pcntl',
            'pdo_dblib', 'pdo_pgsql', 'pgsql', 'pspell', 'shmop', 'snmp', 'sockets', 'sysvmsg', 'sysvsem', 'sysvshm',
            'tidy', 'wddx', 'weakref', 'xdebug', 'xmlrpc', 'xsl', 'yaml'];

        $this->output->writeln('By default, the following extensions are enabled:');
        $this->output->writeln('<info>apcu mysqli opcache pdo pdo_mysql redis zip soap mbstring ftp mysqlnd</info>');
        $this->output->writeln('You can select more extensions below:');
        $this->output->writeln('<info>'.\implode(' ', $availableExtensions).'</info>');

        /************************ Extensions **********************/
        $extensions = [];
        do {
            $question = new Question('Please enter the name of an additional extension you want to install (keep empty to skip): ', '');
            $question->setAutocompleterValues($availableExtensions);
            $question->setValidator(function (string $value) use ($availableExtensions) {
                if (trim($value) !== '' && !\in_array($value, $availableExtensions)) {
                    throw new \InvalidArgumentException('Unknown extension '.$value);
                }

                return trim($value);
            });

            $extension = $helper->ask($this->input, $this->output, $question);

            if ($extension !== '') {
                $service->addImageEnvVariable('PHP_EXTENSION_'.\strtoupper($extension), '1');
                $extensions[] = $extension;
            }
        } while ($extension !== '');
        $this->output->writeln('<info>Enabled extensions: apcu mysqli opcache pdo pdo_mysql redis zip soap mbstring ftp mysqlnd '.\implode(' ', $extensions).'</info>');
        $aentHelper->spacer();
        $aentHelper->spacer();


        /************************ php.ini settings **********************/
        $this->output->writeln("Now, let's customize some settings of <info>php.ini</info>.");

        $memoryLimit = $aentHelper->question('PHP <info>memory limit</info> (keep empty to stay with the default 128M)')
            ->setHelpText('This value will be used in the memory_limit option of PHP via the PHP_INI_MEMORY_LIMIT environment variable.')
            ->setValidator(function (string $value) {
                if (trim($value) !== '' && !\preg_match('/^[0-9]+([MGK])?$/i', $value)) {
                    throw new \InvalidArgumentException('Invalid value: '.$value);
                }

                return trim($value);
            })
            ->ask();
        if ($memoryLimit !== '') {
            $this->output->writeln("<info>Memory limit: $memoryLimit</info>");
            $service->addImageEnvVariable('PHP_INI_MEMORY_LIMIT', $memoryLimit);
        }

        $uploadMaxFileSize = $aentHelper->question('<info>Maximum file size for uploaded files</info> (keep empty to stay with the default 2M)')
            ->setHelpText('This value will be used in the upload_max_file_size and post_max_size options of PHP via the PHP_INI_UPLOAD_MAX_FILESIZE and PHP_INI_POST_MAX_SIZE environment variables.')
            ->setValidator(function (string $value) {
                if (trim($value) !== '' && !\preg_match('/^[0-9]+([MGK])?$/i', $value)) {
                    throw new \InvalidArgumentException('Invalid value: '.$value);
                }

                return trim($value);
            })
            ->ask();

        if ($uploadMaxFileSize !== '') {
            $this->output->writeln("<info>Upload maximum file size: $uploadMaxFileSize</info>");
            $service->addImageEnvVariable('PHP_INI_UPLOAD_MAX_FILESIZE', $uploadMaxFileSize);
            $service->addImageEnvVariable('PHP_INI_POST_MAX_SIZE', $uploadMaxFileSize);
        }

        $aentHelper->spacer();
        $aentHelper->spacer();
        $this->output->writeln('Does your service depends on another service to start? For instance a "mysql" instance?');
        do {
            $question = new Question('Please input a service name your application depends on (keep empty to skip) : ', '');

            $depend = $helper->ask($this->input, $this->output, $question);

            if ($depend !== '') {
                $service->addDependsOn($depend);
                $this->output->writeln('<info>Added dependency: '.$depend.'</info>');
            }
        } while ($depend !== '');
        $aentHelper->spacer();
        $aentHelper->spacer();


        // TODO: propose to run composer install on startup?

        if ($variant === 'apache') {
            $service->addInternalPort(80);
        }

        $commentEvents->dispatchService($service);
        $commentEvents->dispatchImage($service);

        // Now, let's configure the reverse proxy
        if ($variant === 'apache') {
            $commentEvents->dispatchNewVirtualHost($serviceName);
        }

        return null;
    }

    /**
     * @return array[] An array with 3 keys: phpVersions, variants and nodeVersions
     */
    private function getAvailableVersionParts() : array
    {
        $registryClient = new RegistryClient();
        $tags = $registryClient->getImageTagsOnDockerHub('thecodingmachine/php');

        $phpVersions = [];
        $variants = [];
        $nodeVersions = [];

        foreach ($tags as $tag) {
            $parts = \explode('-', $tag);
            if (count($parts) < 3) {
                continue;
            }
            if ($parts[1] !== 'v1') {
                continue;
            }
            $phpVersions[$parts[0]] = true;
            $variants[$parts[2]] = true;
            if (isset($parts[3])) {
                $nodeVersions[$parts[3]] = true;
            }
        }

        $phpVersions = \array_keys($phpVersions);
        $variants = \array_keys($variants);
        $nodeVersions = \array_keys($nodeVersions);

        rsort($phpVersions);
        sort($variants);
        rsort($nodeVersions, SORT_NUMERIC);

        return [
            'phpVersions' => $phpVersions,
            'variants' => $variants,
            'nodeVersions' => $nodeVersions,
        ];
    }
}
