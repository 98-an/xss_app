pipeline {
  agent any

  environment {
    REPORTS        = 'reports'
    APP_NAME       = 'xss_app'
    APP_PORT       = '5002'                     // Port exposé pour les tests DAST
    SONAR_HOST_URL = 'http://16.170.87.165:9000'  // ⚠ adapte si 9003
  }

  options { timestamps(); timeout(time: 30, unit: 'MINUTES') }

  stages {
    stage('Checkout & Prep') {
      steps {
        checkout([$class: 'GitSCM',
          branches: [[name: '*/master']],
          extensions: [[$class: 'CloneOption', shallow: true, depth: 1]],
          userRemoteConfigs: [[url: 'https://github.com/98-an/xss_app.git']]
        ])
        sh '''
          set -eux
          rm -rf ${REPORTS} && mkdir -p ${REPORTS}
          (git rev-parse --short HEAD || echo build-${BUILD_NUMBER}) > .tag
        '''
      }
    }

    stage('Static scans (SAST/SCA)') {
      parallel {
        stage('SonarQube (SAST)') {
          steps {
            withCredentials([string(credentialsId: 'sonar-token', variable: 'SONAR_TOKEN')]) {
              sh '''
                set -eux
                docker pull sonarsource/sonar-scanner-cli:latest || true
                docker run --rm \
                  -e SONAR_HOST_URL=${SONAR_HOST_URL} \
                  -e SONAR_TOKEN=${SONAR_TOKEN} \
                  -v "$PWD":/usr/src \
                  -v "$PWD/.git":/usr/src/.git:ro \
                  --entrypoint bash sonarsource/sonar-scanner-cli:latest -lc '
                    set -eux
                    git config --global --add safe.directory /usr/src || true
                    sonar-scanner \
                      -Dsonar.projectKey='${APP_NAME}' \
                      -Dsonar.projectName='${APP_NAME}' \
                      -Dsonar.sources=src \
                      -Dsonar.exclusions=reports/,.git/** \
                      -Dsonar.scm.provider=git \
                      -Dsonar.language=php
                  '
              '''
            }
          }
        }

        stage('OWASP Dependency-Check (SCA)') {
          steps {
            sh '''
              set -eux
              docker pull owasp/dependency-check:latest || true
              docker run --rm \
                -v "$PWD":/src \
                -v "$PWD/${REPORTS}":/report \
                owasp/dependency-check:latest \
                --scan /src --format HTML --out /report --project ${APP_NAME} || true
            '''
          }
        }

        stage('Trivy FS (code & conf)') {
          steps {
            sh '''
              set -eux
              docker pull aquasec/trivy:latest || true
              docker run --rm -v "$PWD":/project aquasec/trivy:latest fs \
                --scanners vuln,secret,misconfig \
                --format sarif -o /project/${REPORTS}/trivy-fs.sarif /project || true
              docker run --rm -v "$PWD":/project aquasec/trivy:latest fs \
                --scanners vuln,secret,misconfig -f table /project \
                > ${REPORTS}/trivy-fs.txt || true
              printf '<html><body><h2>Trivy FS</h2><pre>%s</pre></body></html>' \
                "$(cat ${REPORTS}/trivy-fs.txt 2>/dev/null || true)" \
                > ${REPORTS}/trivy-fs.html
            '''
          }
        }
      }
    }

    stage('Build image') {
      steps {
        sh '''
          set -eux
          TAG=$(cat .tag)
          docker build -t ${APP_NAME}:${TAG} .
          echo ${APP_NAME}:${TAG} > image.txt
        '''
        archiveArtifacts 'image.txt'
      }
    }

    stage('Trivy Image') {
      when { expression { return fileExists('image.txt') } }
      steps {
        sh '''
          set -eux
          IMG=$(cat image.txt)
          docker run --rm \
            -v /var/run/docker.sock:/var/run/docker.sock \
            -v "$PWD":/project aquasec/trivy:latest image \
            --format sarif -o /project/${REPORTS}/trivy-image.sarif "$IMG" || true
          docker run --rm \
            -v /var/run/docker.sock:/var/run/docker.sock \
            aquasec/trivy:latest image -f table "$IMG" \
            > ${REPORTS}/trivy-image.txt || true
          printf '<html><body><h2>Trivy Image</h2><pre>%s</pre></body></html>' \
            "$(cat ${REPORTS}/trivy-image.txt 2>/dev/null || true)" \
            > ${REPORTS}/trivy-image.html
        '''
      }
    }

    stage('Run app (for DAST)') {
      steps {
        sh '''
          set -eux
          IMG=$(cat image.txt)
          docker rm -f app-under-test || true
          docker run -d --name app-under-test -p ${APP_PORT}:80 "$IMG"
          # Attente de disponibilité
          for i in $(seq 1 30); do
            curl -fsS "http://127.0.0.1:${APP_PORT}" >/dev/null && break || sleep 2
          done
        '''
      }
    }

    stage('ZAP Baseline (DAST)') {
      steps {
        sh '''
          set -eux
          docker pull owasp/zap2docker-stable || docker pull owasp/zap2docker-weekly || true
          docker run --rm --network=host \
            -v "$PWD/${REPORTS}":/zap/wrk \
            owasp/zap2docker-stable \
            zap-baseline.py -t http://127.0.0.1:${APP_PORT} -r zap-baseline.html || true
        '''
      }
    }
  }

  post {
    always {
      // Nettoyage du container d'app si encore présent
      sh 'docker rm -f app-under-test >/dev/null 2>&1 || true'

      // Publie les rapports HTML (ignore si manquants)
      publishHTML([
        [allowMissing: true, alwaysLinkToLastBuild: true, keepAll: true,
         reportDir: "${REPORTS}", reportFiles: "dependency-check-report.html",
         reportName: "OWASP Dependency-Check"],
        [allowMissing: true, alwaysLinkToLastBuild: true, keepAll: true,
         reportDir: "${REPORTS}", reportFiles: "trivy-fs.html",
         reportName: "Trivy FS"],
        [allowMissing: true, alwaysLinkToLastBuild: true, keepAll: true,
         reportDir: "${REPORTS}", reportFiles: "trivy-image.html",
         reportName: "Trivy Image"],
        [allowMissing: true, alwaysLinkToLastBuild: true, keepAll: true,
         reportDir: "${REPORTS}", reportFiles: "zap-baseline.html",
         reportName: "ZAP Baseline"]
      ])

      archiveArtifacts artifacts: "${REPORTS}/*", allowEmptyArchive: true
    }
  }
}
