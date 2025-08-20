pipeline {
    agent any

    environment {
        REPORTS            = 'reports'
        DAST_TARGET        = 'http://16.170.87.165:5002'
        SONAR_HOST_URL     = 'http://16.170.87.165:9000'
        SONAR_PROJECT_KEY  = 'xss_app'
        SONAR_PROJECT_NAME = 'XSS App'
    }

    options {
        timestamps()
        timeout(time: 25, unit: 'MINUTES')
        disableResume()
        buildDiscarder(logRotator(numToKeepStr: '15'))
        durabilityHint('MAX_SURVIVABILITY')
    }

    stages {
        stage('Checkout') {
            steps {
                checkout([$class: 'GitSCM',
                    branches: [[name: '*/master']],
                    extensions: [[$class: 'CloneOption', shallow: true, depth: 1]],
                    userRemoteConfigs: [[url: 'https://github.com/98-an/xss_app', credentialsId: 'git-cred']]
                ])
                sh 'rm -rf ${REPORTS} && mkdir -p ${REPORTS}'
                script {
                    env.SHORT_SHA = sh(script: "git rev-parse --short HEAD", returnStdout: true).trim()
                }
            }
        }

        stage('Python Lint & Tests & Bandit (si projet Python)') {
            when {
                expression {
                    return fileExists('src/requirements.txt') || fileExists('requirements.txt') || fileExists('pyproject.toml')
                }
            }
            steps {
                sh '''
                    set -eux
                    docker run --rm -v "$PWD":/ws -w /ws python:3.11-slim bash -lc '
                        set -eux
                        python -m pip install --upgrade pip
                        if   [ -f src/requirements.txt ]; then pip install --prefer-binary -r src/requirements.txt;
                        elif [ -f requirements.txt ]; then pip install --prefer-binary -r requirements.txt; fi
                        pip install pytest flake8 bandit pytest-cov
                        flake8
                        pytest --maxfail=1 --cov=. --cov-report=xml:coverage.xml --junitxml=pytest-report.xml
                        bandit -r . -f html -o reports/bandit-report.html
                    '
                '''
                junit 'pytest-report.xml'
                publishHTML(target: [
                    reportDir: "${REPORTS}",
                    reportFiles: "bandit-report.html",
                    reportName: "Bandit (Python SAST)",
                    keepAll: true,
                    alwaysLinkToLastBuild: true,
                    allowMissing: false
                ])
            }
        }

        stage('Hadolint (Dockerfile)') {
            when {
                expression { return fileExists('Dockerfile') || fileExists('container/Dockerfile') }
            }
            steps {
                sh '''
                    set -eux
                    DF="Dockerfile"; [ -f "$DF" ] || DF="container/Dockerfile"
                    docker run --rm -i hadolint/hadolint < "$DF"
                '''
            }
        }

        stage('code analysis with sonarqube') {
            environment {
                scannerHome = tool 'SonarServer'
            }
            steps {
                withSonarQubeEnv('SonarServer') {
                    sh
