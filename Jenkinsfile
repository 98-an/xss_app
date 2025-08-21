pipeline {
    agent any
    stages {
        stage('Git Checkout') {
            steps {
                checkout scm
            }
        }
    
        stage('Run SonarQube Analysis') {
            steps {
                script {
                    def scannerHome = tool name: 'sonar-qube', type: 'hudson.plugins.sonar.SonarRunnerInstallation'
                    withSonarQubeEnv('sonar-server') {
                        sh "${scannerHome}/bin/sonar-scanner -Dsonar.projectKey=xssapp2 -Dsonar.sources=src"
                    }
                }
            }
        }

        stage('OWASP Dependency Check') {
            steps {
                dependencyCheck additionalArguments: '--scan ./ --format XML --enableExperimental', odcInstallation: 'DC'
                dependencyCheckPublisher pattern: 'dependency-check-report.xml'
            }
        }

        stage('Docker Build') {
            steps {
                sh 'docker build -t yasdevsec/xssapp:v2 .'
            }
        }

        stage('Trivy Scan') {
            steps {
                script {
                    def dockerImage = "yasdevsec/xssapp:v2"
                    sh "trivy image ${dockerImage} --no-progress --severity HIGH,CRITICAL"
                }
            }
        }

        stage('Push Image to Hub') {
            steps {
                withCredentials([usernamePassword(credentialsId: 'dockerhub', passwordVariable: 'PASS', usernameVariable: 'USER')]) {
                    sh '''
                        echo "$PASS" | docker login -u "$USER" --password-stdin
                        docker images | grep yasdevsec/xssapp || true
                        docker push yasdevsec/xssapp:v2
                    '''
                }
            }
        }

        stage('Deploy Container') {
            steps {
                sh 'docker stop py || true'
                sh 'docker rm -f vulnlab || true'
                sh 'docker run -d --name vulnlab -p 5002:80 yasdevsec/xssapp:v2'
            }
        }

        stage('ZAP Full Scan') {
            options { timeout(time: 30, unit: 'MINUTES') }
            steps {
                sh '''#!/usr/bin/env bash
                    set -euxo pipefail
                    docker pull ghcr.io/zaproxy/zaproxy:stable
                    TARGET="http://13.50.222.204:5002/"
                    docker run --rm --network host \
                        -v "$PWD":/zap/wrk \
                        ghcr.io/zaproxy/zaproxy:stable \
                        zap-full-scan.py -t "$TARGET" \
                                         -r zap-full.html \
                                         -J zap-full.json \
                                         -d -I
                '''
            }
            post {
                always {
                    archiveArtifacts artifacts: 'zap-full.*', allowEmptyArchive: true
                }
            }
        }
    }
}
