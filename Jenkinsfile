pipeline {
    agent any

    environment {
        REPORTS            = 'reports'
        DAST_TARGET        = 'http://16.170.87.165:5002'

        // SonarQube (self-hosted)
        SONAR_HOST_URL     = 'http://16.170.87.165:9000'
        SONAR_PROJECT_KEY  = 'xss_app'
        SONAR_PROJECT_NAME = 'XSS App'
    }

    options {
        timestamps()
        timeout(time: 25, unit: 'MINUTES')
        disableResume() // évite les reprises de builds après redémarrage
        buildDiscarder(logRotator(numToKeepStr: '15')) // évite d’accumuler des anciens runs
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
                script { env.SHORT_SHA = sh(script: "git rev-parse --short HEAD", returnStdout: true).trim() }
            }
        }

        // =========================
        // Python (OPTIONNEL)
        // =========================
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

        /*
        stage('Gitleaks (Secrets)') { ... }
        stage('Semgrep (SAST)') { ... }
        */

        stage('SonarQube') {
            steps {
                withCredentials([string(credentialsId: 'sonar-token', variable: 'SONAR_TOKEN')]) {
                    sh '''
                        set -eux
                        rm -rf .scannerwork || true

                        docker run --rm \
                            -e SONAR_HOST_URL="http://16.170.87.165:9000" \
                            -e SONAR_TOKEN="$SONAR_TOKEN" \
                            -v "$WORKSPACE":/usr/src \
                            sonarsource/sonar-scanner-cli:latest \
                            -Dsonar.projectKey="xss_app" \
                            -Dsonar.projectName="XSS App" \
                            -Dsonar.sources=. \
                            -Dsonar.scm.provider=git \
                            -Dsonar.exclusions="**/.git/**,**/__pycache__/**,**/*.pyc,tests/**"
                    '''
                }
            }
        }

        stage('Build Image') {
            when {
                expression { return fileExists('Dockerfile') || fileExists('container/Dockerfile') }
            }
            steps {
                sh '''
                    set -eux
                    DF="Dockerfile"; [ -f "$DF" ] || DF="container/Dockerfile"
                    TAG=$(git rev-parse --short HEAD || echo latest)
                    docker build -f "$DF" -t xssapp:${TAG} .
                    echo xssapp:${TAG} > image.txt
                '''
                archiveArtifacts 'image.txt'
            }
        }

        stage('Trivy FS') {
            steps {
                sh '''
                    set -eux
                    docker run --rm -v "$PWD":/project aquasec/trivy:latest fs \
                        --scanners vuln,secret,misconfig --format sarif -o /project/${REPORTS}/trivy-fs.sarif /project

                    docker run --rm -v "$PWD":/project aquasec/trivy:latest fs \
                        --scanners vuln,secret,misconfig -f table /project > ${REPORTS}/trivy-fs.txt

                    { echo '<html><body><h2>Trivy FS</h2><pre>'; cat ${REPORTS}/trivy-fs.txt; echo '</pre></body></html>'; } \
                        > ${REPORTS}/trivy-fs.html
                '''
                archiveArtifacts artifacts: "${REPORTS}/trivy-fs.sarif, ${REPORTS}/trivy-fs.txt"
                publishHTML(target: [
                    reportDir: "${REPORTS}",
                    reportFiles: "trivy-fs.html",
                    reportName: "Trivy FS",
                    keepAll: true,
                    alwaysLinkToLastBuild: true,
                    allowMissing: false
                ])
            }
        }

        stage('Trivy Image') {
            when { expression { return fileExists('image.txt') } }
            steps {
                sh '''
                    set -eux
                    IMG=$(cat image.txt)
                    docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v "$PWD":/project \
                        aquasec/trivy:latest image --format sarif -o /project/${REPORTS}/trivy-image.sarif "$IMG"

                    docker run --rm -v /var/run/docker.sock:/var/run/docker.sock \
                        aquasec/trivy:latest image -f table "$IMG" > ${REPORTS}/trivy-image.txt

                    { echo '<html><body><h2>Trivy Image</h2><pre>'; cat ${REPORTS}/trivy-image.txt; echo '</pre></body></html>'; } \
                        > ${REPORTS}/trivy-image.html
                '''
                archiveArtifacts artifacts: "${REPORTS}/trivy-image.sarif, ${REPORTS}/trivy-image.txt"
                publishHTML(target: [
                    reportDir: "${REPORTS}",
                    reportFiles: "trivy-image.html",
                    reportName: "Trivy Image",
                    keepAll: true,
                    alwaysLinkToLastBuild: true,
                    allowMissing: false
                ])
            }
        }

        stage('DAST - ZAP Baseline') {
            options { timeout(time: 8, unit: 'MINUTES') }
            steps {
                sh '''
                    set -eux
                    docker run --rm -v "$PWD/${REPORTS}":/zap/wrk owasp/zap2docker-stable \
                        zap-baseline.py -t "${DAST_TARGET}" -r zap-baseline.html
                '''
                publishHTML(target: [
                    reportDir: "${REPORTS}",
                    reportFiles: "zap-baseline.html",
                    reportName: "ZAP Baseline",
                    keepAll: true,
                    alwaysLinkToLastBuild: true,
                    allowMissing: false
                ])
            }
        }

    }

    post {
        always {
            archiveArtifacts artifacts: "${REPORTS}/*", allowEmptyArchive: false
        }
    }
}
