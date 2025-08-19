pipeline {
  agent any

  environment {
    REPORTS            = 'reports'
    // Cible DAST (ton conteneur XSS publish sur 5002)
    DAST_TARGET        = 'http://16.170.87.165:5002'
    // SonarQube (auto-hébergé)
    SONAR_HOST_URL     = 'http://16.170.87.165:9000'
    SONAR_PROJECT_KEY  = 'xss_app'
    SONAR_PROJECT_NAME = 'XSS App'
  }

  options {
    timestamps()
    timeout(time: 40, unit: 'MINUTES')
  }

  stages {

    stage('Checkout') {
      steps {
        checkout([$class: 'GitSCM',
          branches: [[name: '*/master']],
          extensions: [[$class: 'CloneOption', shallow: true, depth: 2]],
          userRemoteConfigs: [[url: 'https://github.com/98-an/xss_app.git']]
        ])
        sh '''
          set -euo pipefail
          rm -rf "${REPORTS}"
          mkdir -p "${REPORTS}"
        '''
      }
    }

    stage('PHP Lint') {
      steps {
        sh '''
          set -euo pipefail
          docker run --rm -v "$PWD":/ws -w /ws php:8.2-cli bash -lc '
            set -euo pipefail
            if ls -1 */.php >/dev/null 2>&1; then
              # Lint tous les .php (échoue s’il y a une erreur de syntaxe)
              find . -type f -name "*.php" -print0 | xargs -0 -n1 -P 2 php -l
            else
              echo "Aucun fichier PHP trouvé."
            fi
          '
        '''
      }
    }

    stage('Hadolint (Dockerfile)') {
      when { anyOf { fileExists('Dockerfile'); fileExists('container/Dockerfile') } }
      steps {
        sh '''
          set -euo pipefail
          DF=Dockerfile; [ -f Dockerfile ] || DF=container/Dockerfile
          docker run --rm -i hadolint/hadolint < "$DF" | tee "${REPORTS}/hadolint.txt" >/dev/null
          {
            echo '<html><body><h2>Hadolint</h2><pre>';
            cat "${REPORTS}/hadolint.txt";
            echo '</pre></body></html>';
          } > "${REPORTS}/hadolint.html"
        '''
        publishHTML([allowMissing: true, alwaysLinkToLastBuild: true, keepAll: true,
          reportDir: "${REPORTS}", reportFiles: "hadolint.html", reportName: "Hadolint (Dockerfile)"])
      }
    }

    stage('Gitleaks (Secrets)') {
      steps {
        sh '''
          set -euo pipefail
          docker run --rm -v "$PWD":/repo zricethezav/gitleaks:latest detect \
            --no-git -s /repo -f sarif -r /repo/${REPORTS}/gitleaks.sarif --exit-code 0
          {
            echo '<html><body><h2>Gitleaks (résumé)</h2><pre>';
            if [ -f "${REPORTS}/gitleaks.sarif" ]; then
              grep -o '"'"'ruleId'"'"' "${REPORTS}/gitleaks.sarif" | wc -l | xargs echo Findings:
            else
              echo "Aucun rapport généré";
            fi
            echo '</pre></body></html>';
          } > "${REPORTS}/gitleaks.html"
        '''
        archiveArtifacts artifacts: "${REPORTS}/gitleaks.sarif", allowEmptyArchive: true
        publishHTML([allowMissing: true, alwaysLinkToLastBuild: true, keepAll: true,
          reportDir: "${REPORTS}", reportFiles: "gitleaks.html", reportName: "Gitleaks (Secrets)"])
      }
    }

    stage('Semgrep (SAST)') {
      steps {
        sh '''
          set -euo pipefail
          docker run --rm -v "$PWD":/src returntocorp/semgrep:latest semgrep \
            --no-git --config p/ci --sarif --output /src/${REPORTS}/semgrep.sarif --timeout 0
          {
            echo '<html><body><h2>Semgrep (résumé)</h2><pre>';
            if [ -f "${REPORTS}/semgrep.sarif" ]; then
              grep -o '"'"'ruleId'"'"' "${REPORTS}/semgrep.sarif" | wc -l | xargs echo Findings:
            else
              echo "Aucun rapport généré";
            fi
            echo '</pre></body></html>';
          } > "${REPORTS}/semgrep.html"
        '''
        archiveArtifacts artifacts: "${REPORTS}/semgrep.sarif", allowEmptyArchive: true
        publishHTML([allowMissing: true, alwaysLinkToLastBuild: true, keepAll: true,
          reportDir: "${REPORTS}", reportFiles: "semgrep.html", reportName: "Semgrep (SAST)"])
      }
    }

    stage('Dependency-Check (SCA)') {
      steps {
        sh '''
          set -euo pipefail
          # Génère un rapport HTML; on évite l’échec avec un seuil CVSS très haut
          docker run --rm -v "$PWD":/src -v "$PWD/${REPORTS}":/report owasp/dependency-check:latest \
            --scan /src --project "xss_app" --format HTML --out /report --failOnCVSS 11
        '''
        publishHTML([allowMissing: true, alwaysLinkToLastBuild: true, keepAll: true,
          reportDir: "${REPORTS}", reportFiles: "dependency-check-report.html", reportName: "OWASP Dependency-Check"])
      }
    }

    stage('SonarQube (SAST + Qualité)') {
      environment { SONAR_SCANNER_OPTS = '-Xmx1g' }
      steps {
        withCredentials([string(credentialsId: 'sonar-token', variable: 'SONAR_TOKEN')]) {
          sh '''
            set -euo pipefail

            rm -rf .scannerwork || true

            docker run --rm \
              -e SONAR_HOST_URL="${SONAR_HOST_URL}" \
              -e SONAR_TOKEN="${SONAR_TOKEN}" \
              -v "$PWD":/usr/src \
              -v "$PWD/.git":/usr/src/.git:ro \
              --entrypoint bash sonarsource/sonar-scanner-cli:latest -lc '
                set -euo pipefail
                SRC="."
                [ -d src ] && SRC="src"

                sonar-scanner \
                  -Dsonar.projectKey="${SONAR_PROJECT_KEY}" \
                  -Dsonar.projectName="${SONAR_PROJECT_NAME}" \
                  -Dsonar.projectBaseDir=/usr/src \
                  -Dsonar.sources="${SRC}" \
                  -Dsonar.sourceEncoding=UTF-8 \
                  -Dsonar.scm.provider=git \
                  -Dsonar.php.file.suffixes=php \
                  -Dsonar.exclusions=reports/,.scannerwork/,node_modules/,vendor/,/.min.js,/.min.css
              '
          '''
        }
      }
    }

    stage('Build Image') {
      when { anyOf { fileExists('Dockerfile'); fileExists('container/Dockerfile') } }
      steps {
        sh '''
          set -euo pipefail
          DF=Dockerfile; [ -f Dockerfile ] || DF=container/Dockerfile
          TAG=$(git rev-parse --short HEAD || echo latest)
          docker build -f "$DF" -t xss_app:${TAG} .
          echo xss_app:${TAG} > image.txt
        '''
        archiveArtifacts 'image.txt'
      }
    }

    stage('Trivy FS (code & conf)') {
      steps {
        sh '''
          set -euo pipefail
          docker run --rm -v "$PWD":/project aquasec/trivy:latest fs \
            --scanners vuln,secret,misconfig \
            --format sarif -o /project/${REPORTS}/trivy-fs.sarif /project --exit-code 0
          docker run --rm -v "$PWD":/project aquasec/trivy:latest fs \
            --scanners vuln,secret,misconfig -f table /project --exit-code 0 \
            > ${REPORTS}/trivy-fs.txt
          {
            echo '<html><body><h2>Trivy FS</h2><pre>';
            cat "${REPORTS}/trivy-fs.txt";
            echo '</pre></body></html>';
          } > "${REPORTS}/trivy-fs.html"
        '''
        archiveArtifacts artifacts: "${REPORTS}/trivy-fs.sarif, ${REPORTS}/trivy-fs.txt", allowEmptyArchive: true
        publishHTML([allowMissing: true, alwaysLinkToLastBuild: true, keepAll: true,
          reportDir: "${REPORTS}", reportFiles: "trivy-fs.html", reportName: "Trivy FS"])
      }
    }

    stage('Trivy Image') {
      when { expression { return fileExists('image.txt') } }
      steps {
        sh '''
          set -euo pipefail
          IMG=$(cat image.txt)
          docker run --rm -v /var/run/docker.sock:/var/run/docker.sock \
            -v "$PWD":/project aquasec/trivy:latest image \
            --format sarif -o /project/${REPORTS}/trivy-image.sarif "$IMG" --exit-code 0
          docker run --rm -v /var/run/docker.sock:/var/run/docker.sock \
            aquasec/trivy:latest image -f table "$IMG" --exit-code 0 \
            > ${REPORTS}/trivy-image.txt
          {
            echo '<html><body><h2>Trivy Image</h2><pre>';
            cat "${REPORTS}/trivy-image.txt";
            echo '</pre></body></html>';
          } > "${REPORTS}/trivy-image.html"
        '''
        archiveArtifacts artifacts: "${REPORTS}/trivy-image.sarif, ${REPORTS}/trivy-image.txt", allowEmptyArchive: true
        publishHTML([allowMissing: true, alwaysLinkToLastBuild: true, keepAll: true,
          reportDir: "${REPORTS}", reportFiles: "trivy-image.html", reportName: "Trivy Image"])
      }
    }

    stage('DAST - ZAP Baseline') {
      options { timeout(time: 10, unit: 'MINUTES') }
      steps {
        sh '''
          set -euo pipefail
          docker run --rm -v "$PWD/${REPORTS}":/zap/wrk owasp/zap2docker-stable \
            zap-baseline.py -t "${DAST_TARGET}" -r zap-baseline.html -x zap-baseline.xml -a
          # publie un mini wrapper HTML s'il n'y a pas de rapport
          [ -f "${REPORTS}/zap-baseline.html" ] || \
            echo "<html><body><p>Aucun rapport ZAP généré.</p></body></html>" > "${REPORTS}/zap-baseline.html"
        '''
        publishHTML([allowMissing: true, alwaysLinkToLastBuild: true, keepAll: true,
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
