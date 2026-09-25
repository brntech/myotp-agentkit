lint:
	docker run --rm -t -v `pwd`/integration/:/data/integration/ auth0/marketplace-integration-tools npm run integration:lint

test:
	docker run --rm -t -v `pwd`/integration/:/data/integration/ auth0/marketplace-integration-tools npm run test:action

zip:
	zip -r integration-action.zip integration media
