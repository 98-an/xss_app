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
          branches: [[name: '*/master']],              // ou */main si besoin
          userRemoteConfigs: [[url: 'https://github.com/98-an/xss_app.git', credentialsId: 'git-cred']],
          extensions: [[$class: 'CloneOption', shallow: true, depth: 1]]
        ])
        sh 'rm -rf ${REPORTS} && mkdir -p ${REPORTS}'
        sh 'ls -al'
      }
    }

    stage('PHP Lint & Semgrep') {
      steps {
        sh '''
          set -eux
          # Lint PHP (sans composer). On n'échoue pas le build pour débuter.
          docker run --rm -v "$PWD":/ws -w /ws php:8.2-cli bash -lc "
            set -eu
            find src -type f -name '*.php' -print0 | xargs -0 -n1 -P2 php -l \
              > ${REPORTS}/php-lint.txt || true
          " || true

          # Semgrep SAST (PHP inclus dans p/ci)
          docker pull returntocorp/semgrep:latest || true
          docker run --rm -v "$PWD":/src returntocorp/semgrep:latest semgrep \
            --no-git --config p/ci --sarif --output /src/${REPORTS}/semgrep.sarif --error --timeout 0 || true

          # Petit résumé HTML
          ( echo '<html><body><h2>PHP Lint (résumé)</h2><pre>';
            tail -n 200 ${REPORTS}/php-lint.txt 2>/dev/null;
            echo '</pre><hr/><h2>Semgrep (compte)</h2><pre>';
            (grep -o '"'"'ruleId'"'"' ${REPORTS}/semgrep.sarif | wc -l | xargs echo Findings: ) 2>/dev/null;
            echo '</pre></body></html>' ) > ${REPORTS}/lint_semgrep.html
        '''
        archiveArtifacts allowEmptyArchive: true, artifacts: "${REPORTS}/php-lint.txt, ${REPORTS}/semgrep.sarif"
        publishHTML([allowMissing: true, keepAll: true, alwaysLinkToLastBuild: true,
          reportDir: "${REPORTS}", reportFiles: "lint_semgrep.html", reportName: "PHP Lint & Semgrep"])
      }
    }

    stage('Hadolint (Dockerfile)') {
      when { anyOf { fileExists('Dockerfile'); fileExists('container/Dockerfile') } }
      steps {
        sh '''
          set -eux
          DF=Dockerfile; [ -f Dockerfile ] || DF=container/Dockerfile
          docker pull hadolint/hadolint || true
          docker run --rm -i hadolint/hadolint < "$DF" || true
        '''
      }
    }

    stage('Gitleaks (Secrets)') {
      steps {
        sh '''
          set -eux
          docker pull zricethezav/gitleaks:latest || true
          docker run --rm -v "$PWD":/repo zricethezav/gitleaks:latest detect \
            --no-git -s /repo -f sarif -r /repo/${REPORTS}/gitleaks.sarif || true
          ( echo '<html><body><h2>Gitleaks (résumé)</h2><pre>';
            (grep -o '"'"'ruleId'"'"' ${REPORTS}/gitleaks.sarif | wc -l | xargs echo Findings: ) 2>/dev/null;
            echo '</pre></body></html>' ) > ${REPORTS}/gitleaks.html
        '''
        archiveArtifacts allowEmptyArchive: true, artifacts: "${REPORTS}/gitleaks.sarif"
        publishHTML([allowMissing: true, keepAll: true, alwaysLinkToLastBuild: true,
          reportDir: "${REPORTS}", reportFiles: "gitleaks.html", reportName: "Gitleaks (Secrets)"])
      }
    }

    stage('SonarQube - Analyse & Quality Gate') {
      steps {
        withCredentials([string(credentialsId: 'sonarqube-token', variable: 'SONAR_TOKEN')]) {
          withSonarQubeEnv('sonarqube') {
            sh '''
              set -eux
              rm -rf .scannerwork || true
              docker pull sonarsource/sonar-scanner-cli:latest || true
              docker run --rm \
                -e SONAR_HOST_URL="$SONAR_HOST_URL" \
                -e SONAR_TOKEN="$SONAR_TOKEN" \
                -v "$PWD":/usr/src \
                -v "$PWD/.git":/usr/src/.git:ro \
                --entrypoint bash sonarsource/sonar-scanner-cli:latest -lc '
                  set -eux
                  git config --global --add safe.directory /usr/src || true
                  SRC="src"; [ -d src ] || SRC="."
                  ARGS="-Dsonar.projectKey=${SONAR_PROJECT_KEY} \
                        -Dsonar.projectName=${SONAR_PROJECT_NAME} \
                        -Dsonar.projectBaseDir=/usr/src \
                        -Dsonar.sources=${SRC} \
                        -Dsonar.scm.provider=git \
                        -Dsonar.exclusions=reports/,.venv/,.pytest_cache/,_pycache_/,node_modules/** \
                        -Dsonar.php.file.suffixes=php"
                  sonar-scanner $ARGS
                '
            '''
          }
        }
      }
    }

    stage('Quality Gate') {
      steps {
        timeout(time: 10, unit: 'MINUTES') {
          script {
            def qg = waitForQualityGate()   // nécessite le webhook SQ -> Jenkins
            if (qg.status != 'OK') {
              error "Quality Gate FAILED: ${qg.status}"
            }
          }
        }
      }
    }

    stage('Build Docker Image') {
      when { anyOf { fileExists('Dockerfile'); fileExists('container/Dockerfile') } }
      steps {
        sh '''
          set -eux
          DF=Dockerfile; [ -f Dockerfile ] || DF=container/Dockerfile
          TAG=$(git rev-parse --short HEAD || echo latest)
          docker build -f "$DF" -t xss_app:${TAG} .
          echo xss_app:${TAG} > image.txt
        '''
        archiveArtifacts 'image.txt'
      }
    }

    stage('Trivy FS & Image') {
      steps {
        sh '''
          set -eux
          docker pull aquasec/trivy:latest || true
          # FS
          docker run --rm -v "$PWD":/project aquasec/trivy:latest fs \
            --scanners vuln,secret,misconfig --format sarif -o /project/${REPORTS}/trivy-fs.sarif /project || true
          docker run --rm -v "$PWD":/project aquasec/trivy:latest fs -f table /project > ${REPORTS}/trivy-fs.txt || true
          ( echo '<html><body><h2>Trivy FS</h2><pre>'; cat ${REPORTS}/trivy-fs.txt 2>/dev/null; echo '</pre></body></html>' ) > ${REPORTS}/trivy-fs.html

          # Image si disponible
          if [ -f image.txt ]; then
            IMG=$(cat image.txt)
            docker run --rm -v /var/run/docker.sock:/var/run/docker.sock \
              -v "$PWD":/project aquasec/trivy:latest image --format sarif \
              -o /project/${REPORTS}/trivy-image.sarif "$IMG" || true
            docker run --rm -v /var/run/docker.sock:/var/run/docker.sock \
              aquasec/trivy:latest image -f table "$IMG" > ${REPORTS}/trivy-image.txt || true
            ( echo '<html><body><h2>Trivy Image</h2><pre>'; cat ${REPORTS}/trivy-image.txt 2>/dev/null; echo '</pre></body></html>' ) > ${REPORTS}/trivy-image.html
          fi
        '''
        archiveArtifacts allowEmptyArchive: true, artifacts: "${REPORTS}/trivy-fs.sarif, ${REPORTS}/trivy-fs.txt, ${REPORTS}/trivy-image.sarif, ${REPORTS}/trivy-image.txt"
        publishHTML([allowMissing: true, keepAll: true, alwaysLinkToLastBuild: true,
          reportDir: "${REPORTS}", reportFiles: "trivy-fs.html", reportName: "Trivy FS"])
        publishHTML([allowMissing: true, keepAll: true, alwaysLinkToLastBuild: true,
          reportDir: "${REPORTS}", reportFiles: "trivy-image.html", reportName: "Trivy Image"])
      }
    }

    stage('OWASP Dependency-Check') {
      steps {
        sh '''
          set -eux
          docker pull owasp/dependency-check:latest || true
          # Pour rester léger sur t3.micro, on évite l’update (première fois, lance un run séparé avec update si besoin)
          docker run --rm -v "$PWD":/src -v "$PWD/${REPORTS}":/report owasp/dependency-check:latest \
            --scan /src --format "HTML,XML" --out /report --project "xss_app" --noupdate || true
        '''
        publishHTML([allowMissing: true, keepAll: true, alwaysLinkToLastBuild: true,
          reportDir: "${REPORTS}", reportFiles: "dependency-check-report.html", reportName: "OWASP Dependency-Check"])
        archiveArtifacts allowEmptyArchive: true, artifacts: "${REPORTS}/dependency-check-report.html, ${REPORTS}/dependency-check-report.xml"
      }
    }

    stage('DAST - ZAP Baseline') {
      options { timeout(time: 8, unit: 'MINUTES') }
      steps {
        sh '''
          set -eux
          docker pull owasp/zap2docker-stable || docker pull owasp/zap2docker-weekly || true
          ( docker run --rm -v "$PWD/${REPORTS}":/zap/wrk owasp/zap2docker-stable \
              zap-baseline.py -t ${DAST_TARGET} -r zap-baseline.html ) || true
          [ -f ${REPORTS}/zap-baseline.html ] || echo "<html><body><p>ZAP non exécuté.</p></body></html>" > ${REPORTS}/zap-baseline.html
        '''
        publishHTML([allowMissing: true, keepAll: true, alwaysLinkToLastBuild: true,
          reportDir: "${REPORTS}", reportFiles: "zap-baseline.html", reportName: "ZAP Baseline"])
      }
    }
  }

  post {
    always {
      archiveArtifacts allowEmptyArchive: true, artifacts: "${REPORTS}/*"
      echo "Copie manuelle (si tu es sur la VM):"
      echo "docker cp jenkins:/var/jenkins_home/workspace/${JOB_NAME}/reports ./reports_from_jenkins && ls -al ./reports_from_jenkins"
    }
  }
}
