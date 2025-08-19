// Jenkinsfile — xss_app
pipeline {
  agent any

  environment {
    REPORTS = 'reports'
    // Adapter si tu changes d’IP/port:
    DAST_TARGET = 'http://16.170.87.165:5002'
    // Métadonnées SonarQube
    SONAR_PROJECT_KEY  = '98-an_xss_app'
    SONAR_PROJECT_NAME = 'xss_app'
  }

  options {
    timestamps()
    timeout(time: 30, unit: 'MINUTES')
  }

  stages {

    stage('Checkout') {
      steps {
        checkout([$class: 'GitSCM',
          branches: [[name: '*/master']], // adapte à main si besoin
          extensions: [[$class: 'CloneOption', shallow: true, depth: 1]],
          userRemoteConfigs: [[url: 'https://github.com/98-an/python-demoapp.git', credentialsId: 'git-cred']]
        ])
        sh 'rm -rf ${REPORTS} && mkdir -p ${REPORTS}'
      }
    }

    stage('Python Lint & Tests & Bandit') {
      steps {
        sh '''
          set -eux
          docker run --rm -v "$PWD":/ws -w /ws python:3.11-slim bash -lc '
            set -eux
            python -m pip install --upgrade pip
            if [ -f src/requirements.txt ]; then pip install --prefer-binary -r src/requirements.txt;
            elif [ -f requirements.txt ]; then pip install --prefer-binary -r requirements.txt; fi
            pip install pytest flake8 bandit pytest-cov
            flake8
            pytest --maxfail=1 --cov=. --cov-report=xml:reports/coverage.xml --junitxml=pytest-report.xml
            bandit -r . -f html -o reports/bandit-report.html
          '
        '''
        junit 'pytest-report.xml'
        publishHTML([allowMissing: true, alwaysLinkToLastBuild: true, keepAll: true,
          reportDir: "${REPORTS}", reportFiles: "bandit-report.html", reportName: "Bandit (Python SAST)"])
      }
    }

    stage('Hadolint (Dockerfile)') {
      when { anyOf { fileExists('Dockerfile'); fileExists('container/Dockerfile') } }
      steps {
        sh '''
          set -eux
          DF=Dockerfile; [ -f Dockerfile ] || DF=container/Dockerfile
          docker pull hadolint/hadolint
          docker run --rm -i hadolint/hadolint < "$DF"
        '''
      }
    }

    stage('Gitleaks (Secrets)') {
      steps {
        sh '''
          set -eux
          docker pull zricethezav/gitleaks:latest
          docker run --rm -v "$PWD":/repo zricethezav/gitleaks:latest detect \
            --no-git -s /repo -f sarif -r /repo/${REPORTS}/gitleaks.sarif
          ( echo '<html><body><h2>Gitleaks (résumé)</h2><pre>';
            (grep -o '"'"'ruleId'"'"' ${REPORTS}/gitleaks.sarif | wc -l | xargs echo Findings: );
            echo '</pre></body></html>' ) > ${REPORTS}/gitleaks.html
        '''
        archiveArtifacts artifacts: "${REPORTS}/gitleaks.sarif", allowEmptyArchive: false
        publishHTML([allowMissing: true, alwaysLinkToLastBuild: true, keepAll: true,
          reportDir: "${REPORTS}", reportFiles: "gitleaks.html", reportName: "Gitleaks (Secrets)"])
      }
    }

    stage('Semgrep (SAST)') {
      steps {
        sh '''
          set -eux
          docker pull returntocorp/semgrep:latest
          docker run --rm -v "$PWD":/src returntocorp/semgrep:latest semgrep \
            --no-git --config p/ci --sarif --output /src/${REPORTS}/semgrep.sarif --error --timeout 0
          ( echo '<html><body><h2>Semgrep (résumé)</h2><pre>';
            (grep -o '"'"'ruleId'"'"' ${REPORTS}/semgrep.sarif | wc -l | xargs echo Findings: );
            echo '</pre></body></html>' ) > ${REPORTS}/semgrep.html
        '''
        archiveArtifacts artifacts: "${REPORTS}/semgrep.sarif", allowEmptyArchive: false
        publishHTML([allowMissing: true, alwaysLinkToLastBuild: true, keepAll: true,
          reportDir: "${REPORTS}", reportFiles: "semgrep.html", reportName: "Semgrep (SAST)"])
      }
    }

    stage('SonarQube - Analyse') {
      steps {
        withCredentials([string(credentialsId: 'sonarqube-token', variable: 'SQ_TOKEN')]) {
          withSonarQubeEnv('sonarqube') {
            sh '''
              set -eux
              rm -rf .scannerwork
              docker pull sonarsource/sonar-scanner-cli:latest
              docker run --rm \
                -e SONAR_HOST_URL="$SONAR_HOST_URL" \
                -e SONAR_TOKEN="$SQ_TOKEN" \
                -v "$PWD":/usr/src \
                -v "$PWD/.git":/usr/src/.git:ro \
                --entrypoint sonar-scanner sonarsource/sonar-scanner-cli:latest \
                  -Dsonar.projectKey=CHANGER_projectKey \
                  -Dsonar.projectName=CHANGER_projectName \
                  -Dsonar.projectBaseDir=/usr/src \
                  -Dsonar.sources=. \
                  -Dsonar.scm.provider=git \
                  -Dsonar.python.version=3.11 \
                  -Dsonar.exclusions=reports/,.venv/,.pytest_cache/,_pycache_/,node_modules/** \
                  -Dsonar.tests=tests \
                  -Dsonar.python.coverage.reportPaths=reports/coverage.xml
            '''
          }
        }
      }
    }

    stage('SonarQube - Quality Gate') {
      steps {
        timeout(time: 5, unit: 'MINUTES') {
          waitForQualityGate abortPipeline: true
        }
      }
    }

    stage('Build Image (si Dockerfile présent)') {
      when { anyOf { fileExists('Dockerfile'); fileExists('container/Dockerfile') } }
      steps {
        sh '''
          set -eux
          DF=Dockerfile; [ -f Dockerfile ] || DF=container/Dockerfile
          TAG=$(git rev-parse --short HEAD || echo latest)
          docker build -f "$DF" -t demoapp:${TAG} .
          echo demoapp:${TAG} > image.txt
        '''
        archiveArtifacts 'image.txt'
      }
    }

    stage('Trivy FS (deps & conf)') {
      steps {
        sh '''
          set -eux
          docker pull aquasec/trivy:latest
          docker run --rm -v "$PWD":/project aquasec/trivy:latest fs \
            --scanners vuln,secret,misconfig \
            --exit-code 1 --severity HIGH,CRITICAL \
            --format sarif -o /project/${REPORTS}/trivy-fs.sarif /project
          docker run --rm -v "$PWD":/project aquasec/trivy:latest fs \
            --scanners vuln,secret,misconfig \
            --exit-code 1 --severity HIGH,CRITICAL \
            -f table /project > ${REPORTS}/trivy-fs.txt
          ( echo '<html><body><h2>Trivy FS</h2><pre>'; cat ${REPORTS}/trivy-fs.txt; echo '</pre></body></html>' ) > ${REPORTS}/trivy-fs.html
        '''
        archiveArtifacts artifacts: "${REPORTS}/trivy-fs.sarif, ${REPORTS}/trivy-fs.txt", allowEmptyArchive: false
        publishHTML([allowMissing: true, alwaysLinkToLastBuild: true, keepAll: true,
          reportDir: "${REPORTS}", reportFiles: "trivy-fs.html", reportName: "Trivy FS"])
      }
    }

    stage('Trivy Image (si image.txt)') {
      when { expression { return fileExists('image.txt') } }
      steps {
        sh '''
          set -eux
          IMG=$(cat image.txt)
          docker run --rm -v /var/run/docker.sock:/var/run/docker.sock \
            -v "$PWD":/project aquasec/trivy:latest image \
            --exit-code 1 --severity HIGH,CRITICAL \
            --format sarif -o /project/${REPORTS}/trivy-image.sarif "$IMG"
          docker run --rm -v /var/run/docker.sock:/var/run/docker.sock \
            aquasec/trivy:latest image --exit-code 1 --severity HIGH,CRITICAL \
            -f table "$IMG" > ${REPORTS}/trivy-image.txt
          ( echo '<html><body><h2>Trivy Image</h2><pre>'; cat ${REPORTS}/trivy-image.txt; echo '</pre></body></html>' ) > ${REPORTS}/trivy-image.html
        '''
        archiveArtifacts artifacts: "${REPORTS}/trivy-image.sarif, ${REPORTS}/trivy-image.txt", allowEmptyArchive: false
        publishHTML([allowMissing: true, alwaysLinkToLastBuild: true, keepAll: true,
          reportDir: "${REPORTS}", reportFiles: "trivy-image.html", reportName: "Trivy Image"])
      }
    }

    stage('DAST - ZAP Baseline') {
      options { timeout(time: 8, unit: 'MINUTES') }
      steps {
        sh '''
          set -eux
          docker pull owasp/zap2docker-stable || docker pull owasp/zap2docker-weekly
          docker run --rm -v "$PWD/${REPORTS}":/zap/wrk owasp/zap2docker-stable \
            zap-baseline.py -t "${DAST_TARGET}" -r zap-baseline.html
          [ -f ${REPORTS}/zap-baseline.html ]
        '''
        publishHTML([allowMissing: false, alwaysLinkToLastBuild: true, keepAll: true,
          reportDir: "${REPORTS}", reportFiles: "zap-baseline.html", reportName: "ZAP Baseline"])
      }
    }
  }

  post {
    always {
      archiveArtifacts artifacts: "${REPORTS}/*", allowEmptyArchive: true
    }
  }
}
