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
        disableResume() 
        buildDiscarder(logRotator(numToKeepStr: '15'))
        durabilityHint('MAX_SURVIVABILITY')
    }

    stages {
        stage('Checkout') {
            steps {
                checkout([
                    $class: 'GitSCM',
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

        stage('Code analysis with SonarQube') {
            environment {
                scannerHome = tool 'SonarServer'
            }
            steps {
                withSonarQubeEnv('SonarServer') {
                    sh '''${scannerHome}/bin/sonar-scanner \
                        -Dsonar.projectKey=xssapp \
                        -Dsonar.projectName=xssapp \
                        -Dsonar.projectVersion=1.0 \
                        -Dsonar.sources=src \
                        -Dsonar.organization=anakar'''
                }
            }
        }
    }

    post {
        always {
            archiveArtifacts artifacts: "${REPORTS}/*", allowEmptyArchive: false
        }
        success {
            echo 'Analyse SonarQube terminée avec succès.'
        }
        failure {
            echo 'Échec de l\'analyse SonarQube.'
        }
    }
}
