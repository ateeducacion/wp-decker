const { screen, fireEvent } = require( '@testing-library/dom' );
require( '@testing-library/jest-dom/extend-expect' );

beforeEach(
	() => {
		document.body.innerHTML = \`
		< div >
		< button id = "my-button" > Click me < / button >
		< span id = "result" > < / span >
		< / div >
		\`;
		require( '../../assets/script.js' );
	}
);

test(
	'button click updates result',
	() => {
		const button = screen.getByText( 'Click me' );
		fireEvent.click( button );
		const result = screen.getByText( 'Clicked!' );
		expect( result ).toBeInTheDocument();
	}
);
