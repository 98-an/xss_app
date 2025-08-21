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
                sh 'docker stop vulnlab || true'
                sh 'docker rm vulnlab || true'
                sh 'docker run -d --name vulnlab -p 5000:5000 yasdevsec/xssapp:v2'
            }
        }

        stage('ZAP Full Scan') {
            options { timeout(time: 30, unit: 'MINUTES') }
            steps {
                sh '''#!/usr/bin/env bash
                    set -euxo pipefail

                    # 1) tirer ZAP (repo officiel sur GHCR)
                    docker pull ghcr.io/zaproxy/zaproxy:stable

                    # 2) définir la cible : ton conteneur xssapp est exposé sur le port 5000
                    #    - si Jenkins est sur la même VM que le conteneur, on peut utiliser --network host + http://localhost:5000
                    #    - sinon, mets l'IP publique de la VM (ex: http://13.50.222.204:5000)
                    TARGET="http://localhost:5000"

                    # 3) lancer un scan complet (spider + active scan) et générer des rapports
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
